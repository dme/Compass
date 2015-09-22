<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use GuzzleHttp;
use DB;

class IndieAuth extends BaseController
{
  private function _redirectURI() {
    return env('BASE_URL') . 'auth/callback';
  }

  public function start(Request $request) {
    $me = \IndieAuth\Client::normalizeMeURL($request->input('me'));
    if(!$me) {
      return view('auth/error', ['error' => 'Invalid URL']);
    }

    $state = \IndieAuth\Client::generateStateParameter();

    if(preg_match('/https?:\/\/github\.com\/[^ \/]+/', $me)) {
      $authorizationURL = 'https://github.com/login/oauth/authorize'
        . '?client_id=' . env('GITHUB_ID')
        . '&state=' . $state;

      session([
        'auth_state' => $state, 
        'attempted_me' => $me,
      ]);

    } else {
      $authorizationEndpoint = \IndieAuth\Client::discoverAuthorizationEndpoint($me);
      $tokenEndpoint = \IndieAuth\Client::discoverTokenEndpoint($me);

      session([
        'auth_state' => $state, 
        'attempted_me' => $me,
        'authorization_endpoint' => $authorizationEndpoint,
        'token_endpoint' => $tokenEndpoint
      ]);

      // If the user specified only an authorization endpoint, use that
      if(!$authorizationEndpoint) {
        // Otherwise, fall back to indieauth.com
        $authorizationEndpoint = env('DEFAULT_AUTH_ENDPOINT');
      }
      $authorizationURL = \IndieAuth\Client::buildAuthorizationURL($authorizationEndpoint, $me, $this->_redirectURI(), env('BASE_URL'), $state);
    }

    return redirect($authorizationURL);
  }

  public function callback(Request $request) {
    if(!session('auth_state') || !session('attempted_me')) {
      return view('auth/error', ['error' => 'Missing state information. Start over.']);
    }

    if($request->input('error')) {
      return view('auth/error', ['error' => $request->input('error')]);
    }

    if(session('auth_state') != $request->input('state')) {
      return view('auth/error', ['error' => 'State did not match. Start over.']);
    }

    $tokenEndpoint = false;
    if(session('token_endpoint')) {
      $tokenEndpoint = session('token_endpoint');
    } else if(session('authorization_endpoint')) {
      $authorizationEndpoint = session('authorization_endpoint');
    } else {
      $authorizationEndpoint = env('DEFAULT_AUTH_ENDPOINT');
    }
    if($tokenEndpoint) {
      $token = \IndieAuth\Client::getAccessToken($tokenEndpoint, $request->input('code'), session('attempted_me'), $this->_redirectURI(), env('BASE_URL'), $request->input('state'));
    } else {
      $token = \IndieAuth\Client::verifyIndieAuthCode($authorizationEndpoint, $request->input('code'), session('attempted_me'), $this->_redirectURI(), env('BASE_URL'), $request->input('state'));
    }

    if($token && array_key_exists('me', $token)) {
      session()->flush();
      session(['me' => $token['me']]);
      $this->_userLoggedIn($token['me']);
    }

    return redirect('/');
  }

  public function github(Request $request) {
    if(!session('auth_state') || !session('attempted_me')) {
      return view('auth/error', ['error' => 'Missing state information. Start over.']);
    }

    if($request->input('error')) {
      return view('auth/error', ['error' => $request->input('error')]);
    }

    if(session('auth_state') != $request->input('state')) {
      return view('auth/error', ['error' => 'State did not match. Start over.']);
    }

    if(!$request->input('code')) {
      return view('auth/error', ['error' => 'An unknown error occurred']);
    }

    $client = new GuzzleHttp\Client([
      'http_errors' => false
    ]);
    $res = $client->post('https://github.com/login/oauth/access_token', [
      'form_params' => [
        'client_id' => env('GITHUB_ID'),
        'client_secret' => env('GITHUB_SECRET'),
        // 'redirect_uri' => env('BASE_URL') . 'auth/github',
        'code' => $request->input('code'),
        'state' => session('auth_state')
      ],
      'headers' => [
        'Accept' => 'application/json'
      ]
    ]);
    if($res->getStatusCode() == 200) {
      $body = $res->getBody();
      $data = json_decode($body);
      if($data) {
        if(property_exists($data, 'access_token')) {

          // Now check the username of the user that just logged in
          $res = $client->get('https://api.github.com/user', [
            'headers' => [
              'Authorization' => 'token ' . $data->access_token
            ]
          ]);
          if($res->getStatusCode() == 200) {
            $data = json_decode($res->getBody());
            if(property_exists($data, 'login')) {
              session()->flush();
              $me = 'https://github.com/' . $data->login;
              session(['me' => $me]);
              $this->_userLoggedIn($me);
              return redirect('/');
            } else {
              return view('auth/error', ['error' => 'Login failed']);
            }
          } else {
            return view('auth/error', ['error' => 'Login failed']);
          }
        } else {
          $err = '';
          if(property_exists($data, 'error_description')) {
            $err = ': ' . $data->error_description;
          }
          return view('auth/error', ['error' => 'Login failed' . $err]);
        }
      } else {
        return view('auth/error', ['error' => 'Error parsing response body from GitHub']);
      }
    } else {
      return view('auth/error', ['error' => 'Could not verify login from GitHub: ' . $res->getBody()]);
    }
  }

  private function _userLoggedIn($url) {
    // Create the user record if it doesn't exist yet
    $user = DB::select('SELECT *
      FROM users
      WHERE url = ?', [$url]);
    if(count($user)) {
      DB::update('UPDATE users SET last_login = ?', [date('Y-m-d H:i:s')]);
      session(['user_id' => $user[0]->id]);
    } else {
      DB::insert('INSERT INTO users (url, created_at, last_login) VALUES(?,?,?)', [$url, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
      $user = DB::select('SELECT *
        FROM users
        WHERE url = ?', [$url]);
      session(['user_id' => $user[0]->id]);
    }
  }

  public function logout(Request $request) {
    session()->flush();
    return redirect('/');
  }

}