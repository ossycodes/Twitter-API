<?php

/*
 * This file is part of the YATA package.
 *
 * (c) Colin DeCarlo <colin@thedecarlos.ca>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YATA\Request;

use YATA\Request;

class OAuth extends Request
{

  private $_oAuthHeaders = array();
  private $_consumerSecretKey = null;
  private $_consumerKey = null;
  private $_oauthTokenSecret = null;
  private $_oauthToken = null;

  protected function _init()
  {
    parent::_init();

    // set some static, reasonably default oauth headers
    $this->setOAuthHeaders(array('oauth_version' => '1.0',
                                 'oauth_signature_method' => 'HMAC-SHA1',
                                ), true);

  }

  public function setOauthTokenSecret($secret)
  {
    $this->_oauthTokenSecret = $secret;
    return $this;
  }

  public function getOauthTokenSecret()
  {
    return $this->_oauthTokenSecret;
  }

  public function setConsumerSecretKey($key)
  {
    $this->_consumerSecretKey = $key;
    return $this;
  }

  public function getConsumerSecretKey()
  {
    return $this->_consumerSecretKey;
  }

  public function setConsumerKey($key)
  {
    $this->_consumerKey = $key;
    return $this->setOAuthHeader('oauth_consumer_key',$key,true);
  }

  public function getConsumerKey()
  {
    return $this->_consumerKey;
  }

  public function setOauthToken($token)
  {
    $this->_oauthtoken = $token;
    return $this->setOAuthHeader('oauth_token',$token,true);
  }

  public function getOauthToken()
  {
    return $this->_oauthToken;
  }

  public function setOAuthHeaders(array $headers, $overwrite = false)
  {
    foreach ($headers as $header => $value) {
      $this->setOAuthHeader($header, $value, $overwrite);
    }
  }

  public function setOAuthHeader($header, $value, $overwrite = false)
  {

    // set the oAuth header
    // the oAuth spec says that the headers must be sorted by lexigraphical 
    // order *and* in the event of a tie, then the value is used as the tie 
    // breaker, this means that a header could appear *more than one time* 

    if (isset($this->_oAuthHeaders[$header]) && !$overwrite) {
      if (is_array($this->_oAuthHeaders[$header])) {
        $this->_oAuthHeaders[$header][] = $value;
      } else {
        $this->_oAuthHeaders[$header] = array($this->_oAuthHeaders[$header], $value);
      }
    } else {
      $this->_oAuthHeaders[$header] = $value;
    }

    return $this;

  }

  private function _generateSignatureBaseString()
  {

    $requestMethod = $this->getHttpRequestType();
    $baseUri = $this->getRequestUrl();

    $this->_sortOAuthHeaders();
    
    $params = array_merge($this->_oAuthHeaders, array_map('rawurlencode',$this->_parameters));
    ksort($params);

    $baseString = $requestMethod . '&' . rawurlencode($baseUri) . '&';
    
    $encodedParams = array();
    foreach ($params as $key => $value) {

      if (is_array($value)) {
        foreach ($value as $subValue) {
          $encodedParams[] = rawurlencode($key) . '%3D' . rawurlencode($subValue);
        }
      } else {
        $encodedParams[] = rawurlencode($key) . '%3D' . rawurlencode($value);
      }

    }

    $baseString .= implode('%26', $encodedParams);

    return $baseString;

  }

  private function _generateOAuthSignature()
  {
    $key = rawurlencode($this->_consumerSecretKey) . '&';
    if (isset($this->_oauthTokenSecret)) {
      $key .= rawurlencode($this->_oauthTokenSecret);
    }

    $hash = hash_hmac('sha1',$this->_generateSignatureBaseString(),$key, true);
    $signature = base64_encode($hash);

    return $signature;
  }

  private function _getAuthenticationHeader()
  {
    // create a nonce and timestamp the request
    $this->setOAuthHeader('oauth_timestamp', time(), true);
    $this->setOAuthHeader('oauth_nonce', $this->_generateNonce(), true);
    
    // generate the signature
    $signature = $this->_generateOAuthSignature();

    $gluedHeaders = array();
    foreach ($this->_oAuthHeaders as $header => $value) {
      $gluedHeaders[] = sprintf("%s=\"%s\"", $header, $value);
    }
    $gluedHeaders[] = sprintf("%s=\"%s\"", 'oauth_signature',rawurlencode($signature));

    $header = 'Authorization: OAuth ' . implode(', ', $gluedHeaders);

    return $header;

  }

  private function _generateNonce()
  {

    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $chars .= 'abcdefghijklmnopqrstuvwxyz';
    $chars .= '0123456789';

    $nonce = '';
    for ($i = 0; $i < 44; $i++) {
      $nonce .= substr($chars, (rand() % (strlen($chars))), 1); 
    }

    return $nonce;

  }

  private function _sortOAuthHeaders()
  {

    // first iterate over the headers sorting the headers that have more than 
    // one value
    foreach ($this->_oAuthHeaders as $header => $value) {
      if (is_array($value)) {
        sort($value);
        $this->_oAuthHeaders[$header] = $value;
      }
    }

    // now ksort that bitch
    ksort($this->_oAuthHeaders);

  }

  public function send()
  {

    $ch = curl_init();

    curl_setopt($ch, CURLINFO_HEADER_OUT, true);

    // add the oauth header
    curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->_getAuthenticationHeader()));
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // build the url and set the query string or the post body
    $url = strtr($this->_config['request_url'], array('%format%' => $this->_config['format']));
    $queryString = http_build_query($this->_parameters);
    if ($this->_config['http_request_type'] == 'GET') {
      $url .= '?'.$queryString;
    } else {
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->_parameters));
    }
    curl_setopt($ch, CURLOPT_URL, $url);

    // since the default curl request is GET we only have to change it if its 
    // *not* GET
    if ($this->_config['http_request_type'] != 'GET') {

      switch ($this->_config['http_request_type']) {

        case 'POST':
          curl_setopt($ch, CURLOPT_POST, true);
          break;

        case 'PUT':
          curl_setopt($ch, CURLOPT_PUT, true);
          break;

        default:
          throw new Exception('Unknown http_request_type: ' . $this->_config['http_request_type']);

      }
    }

    $response = curl_exec($ch);

    if ($response === false) {
      throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    return $response;

  }

}
