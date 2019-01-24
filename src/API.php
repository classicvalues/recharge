<?php
namespace Huel\Recharge;

class API {
    private $access_token = '';

    private $ch;

    /**
     * Creates a new API instance. Data argument is passed onto setup().
     */
    public function __construct($data) {
        $this->setup($data);
    }

    /**
     * Overrides the default setup with the given settings.
     */
    public function setup($data) {
        if (isset($data['ACCESS_TOKEN'])) {
            $this->access_token = $data['ACCESS_TOKEN'];
        }
    }

    public function call($method = 'GET', $url = '/', $data = [], $options = []) {

        // Setup options
	    $defaults = [
		    'charset'        => 'UTF-8',
			'headers'        => array(),
	        'fail_on_error'  => TRUE,
	        'return_array'   => FALSE,
	        'all_data'       => FALSE,
            'verify_data'    => TRUE,
            'ignore_response'  => FALSE
	    ];
        $options = array_merge($defaults, $options);

        // Data -> GET Params
        $method = strtoupper($method);
        if ($method === 'GET' && $data) {
            if (!is_array($data)) {
                $data = json_decode($data);
                if (json_last_error() != JSON_ERROR_NONE) {
                    throw new \Exception('Data is malformed. Provide an array OR json-encoded object/array.');
                }
            }

            if (strpos($url, '?') === false) {
                $url .= '?';
            } else {
                $url .= '&';
            }
            $url .= http_build_query($data);
        }

        // Setup headers
        $defaultHeaders = [];
	    $defaultHeaders[] = 'Content-Type: application/json; charset=' . $options['charset'];
	    $defaultHeaders[] = 'Accept: application/json';

        if ($this->access_token) {
		    $defaultHeaders[] = 'X-Recharge-Access-Token: ' . $this->access_token;
        }
        $headers = array_merge($defaultHeaders, $options['headers']);

        // Setup URL
        if ($options['verify_data']) {
            $url = 'https://api.rechargeapps.com' . $url;
        }

        // Setup CURL
        if (!$this->ch) {
            $this->ch = curl_init();
        }
        $ch = $this->ch;

        $curlOpts = array(
            CURLOPT_RETURNTRANSFER  => TRUE,
            CURLOPT_URL             => $url,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CUSTOMREQUEST   => strtoupper($method),
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'Huel reCharge API Wrapper',
            CURLOPT_FAILONERROR     => $options['fail_on_error'],
            CURLOPT_VERBOSE         => $options['all_data'],
            CURLOPT_HEADER          => 1,
            CURLOPT_NOSIGNAL        => 0,
            CURLOPT_TIMEOUT_MS      => 8000,
        );

        if (!$data || $curlOpts[CURLOPT_CUSTOMREQUEST] === 'GET') {
            $curlOpts[CURLOPT_POSTFIELDS] = '';
        } else {
            if (is_array($data)) {
                $curlOpts[CURLOPT_POSTFIELDS] = json_encode($data);
            } else {
                // Detect if already a JSON object
                json_decode($request['DATA']);
                if (json_last_error() == JSON_ERROR_NONE) {
                    $curlOpts[CURLOPT_POSTFIELDS] = $data;
                } else {
                    throw new \Exception('Data is malformed. Provide an array OR json-encoded object/array.');
                }
            }
        }

        if ($options['ignore_response']) {
            $curlOpts[CURLOPT_WRITEFUNCTION] = function($curl, $input) {
                return 0;
            };
            $curlOpts[CURLOPT_RETURNTRANSFER] = null;
            $curlOpts[CURLOPT_TIMEOUT_MS] = 1;
            $curlOpts[CURLOPT_NOSIGNAL] = 1;
        }
        curl_setopt_array($ch, $curlOpts);

        // Make request

        $response = null;
        $headerSize = null;
        $result = null;
        $info = null;
        $returnError = null;

        $retry = true;
        while($retry) {
            $retry = false;

            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $result = json_decode(substr($response, $headerSize), $options['return_array']);

            $info = array_filter(array_map('trim', explode("\n", substr($response, 0, $headerSize))));


            // Parse errors
            $returnError = [
                'number' => curl_errno($ch),
                'msg' =>  curl_error($ch)
            ];
            // curl_close($ch);

            // Parse extra info
            $returnInfo = null;
            foreach($info as $k => $header)
            {
                if (strpos($header, 'HTTP/') > -1)
                {
                    $returnInfo['HTTP_CODE'] = $header;
                    continue;
                }
                list($key, $val) = explode(':', $header);
                $returnInfo[trim($key)] = trim($val);
            }

    	    if ($returnError['number']) {
                // if recharge has taken too long
                if (in_array($returnError['number'], [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED])) {
                    $retry = true;
                    \Log::debug('[Recharge\API] Request timed out, let\'s try again');
                    continue;
                }

                if (isset($returnInfo['HTTP_CODE']) && $returnInfo['HTTP_CODE'] == 'HTTP/1.1 409 CONFLICT') {
                    \Log::info('[Recharge\API] Sleeping for 2 seconds (409 Conflict)');
                    sleep(2);
                    $retry = true;
                    continue;
                }

                if ($returnError['msg'] == 'The requested URL returned error: 429 TOO MANY REQUESTS') {
                    \Log::info('[Recharge\API] Sleeping for 4 seconds (Too Many Requests)');
                    sleep(4);
                    $retry = true;
                    continue;
                }

    		    throw new \Exception('ERROR #' . $returnError['number'] . ': ' . $returnError['msg']);
    	    }
        }


        if ($options['all_data']) {
            if ($options['return_array']) {
                $result['_ERROR'] = $returnError;
                $result['_INFO'] = $returnInfo;
            } else {
                $result->_ERROR = $returnError;
                $result->_INFO = $returnInfo;
            }
            return $result;
        }
        return $result;
    }
}
