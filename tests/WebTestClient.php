<?php

declare(strict_types=1);

use ManufacturingErp\Value;

final class WebTestClient
{
    private string $cookie = '';

    public function __construct(private readonly string $base)
    {
    }

    public function get(string $route, bool $json = true): array
    {
        return $this->request($route, null, '', $json);
    }

    public function post(string $action, array $data, string $csrf): array
    {
        return $this->request('/?action=' . rawurlencode($action), $data, $csrf, true);
    }

    public function publishPlan(int $id,array $fields,string $csrf): array
    {
        return $this->request('/network-plans/'.$id.'/publish',$fields,$csrf,true);
    }

    public function uploadForecast(string $csv, string $batch, string $key, string $csrf): array
    {
        $boundary = 'erp-forecast-' . bin2hex(random_bytes(12));
        $body = '';
        foreach (['batch'=>$batch, 'request_key'=>$key, '_csrf'=>$csrf] as $name=>$value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"forecast_file\"; filename=\"forecast.csv\"\r\n"
            . "Content-Type: text/csv\r\n\r\n$csv\r\n--$boundary--\r\n";
        $response = file_get_contents($this->base . '/?action=import-weekly-forecast', false, stream_context_create([
            'http' => ['method'=>'POST', 'ignore_errors'=>true, 'timeout'=>20, 'content'=>$body,
                'header'=>['Accept: application/json','Cookie: '.$this->cookie, 'Content-Type: multipart/form-data; boundary='.$boundary]]
        ]));
        return ['status'=>(int) explode(' ', $http_response_header[0])[1], 'body'=>Value::fromJson($response)];
    }

    public function submit(string $action, array $data, string $csrf): array
    {
        return $this->request('/?action=' . rawurlencode($action), $data + ['_csrf' => $csrf], '', false, true);
    }

    public function submitAndDropResponse(string $action, array $data, string $csrf, callable $committed): void
    {
        $host = substr($this->base, strlen('http://'));
        $socket = stream_socket_client('tcp://' . $host, timeout: 5);
        if ($socket === false) {
            throw new RuntimeException('Could not connect to the disposable HTTP server.');
        }

        try {
            $body = http_build_query($data + ['_csrf' => $csrf]);
            $request = 'POST /?action=' . rawurlencode($action) . " HTTP/1.1\r\n"
                . "Host: $host\r\nCookie: $this->cookie\r\nConnection: close\r\nAccept: text/html\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
            if (fwrite($socket, $request) !== strlen($request)) {
                throw new RuntimeException('The test request was not sent in full.');
            }
            $deadline = hrtime(true) + 5_000_000_000;
            while (!$committed()) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('The receipt did not become durable before the client disconnected.');
                }
                usleep(20_000);
            }
        } finally {
            fclose($socket);
        }
    }

    private function request(string $route, ?array $data, string $csrf, bool $json, bool $form = false): array
    {
        $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html'), 'Connection: close'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        if ($data !== null) {
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        }
        if ($csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        $stream = stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => $headers,
            'content' => $data === null ? '' : ($form ? http_build_query($data) : Value::json($data)),
            'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $body = file_get_contents($this->base . $route, false, $stream);
        if ($body === false) {
            throw new RuntimeException('The ERP HTTP server did not respond.');
        }
        $raw = $http_response_header;
        $status = (int) explode(' ', array_shift($raw))[1];
        $response = [];
        foreach ($raw as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $response[strtolower($name)] = $value;
        }
        if (isset($response['set-cookie'])) {
            $this->cookie = explode(';', $response['set-cookie'], 2)[0];
        }
        return ['status' => $status, 'headers' => $response, 'body' => $json ? Value::fromJson($body) : $body];
    }
}
