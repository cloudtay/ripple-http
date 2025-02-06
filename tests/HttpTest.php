<?php declare(strict_types=1);


use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\Http\Guzzle;
use Ripple\Http\Server\Request;
use Ripple\Promise;
use Ripple\Utils\Output;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function Co\async;
use function Co\cancelAll;

/**
 *
 */
class HttpTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_httpServer(): void
    {
        $context = \stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server = new Ripple\Http\Server('http://127.0.0.1:8008', $context);
        $server->onRequest(function (Request $request) {
            $url    = \trim($request->SERVER['REQUEST_URI']);
            $method = \strtoupper($request->SERVER['REQUEST_METHOD']);

            if ($url === '/upload') {
                /*** @var UploadedFile $file */
                $file = $request->FILES['file'][0];
                $hash = $request->POST['hash'] ?? '';
                $this->assertEquals($hash, \md5_file($file->getRealPath()));
                $request->respond(\fopen($file->getRealPath(), 'r'));
                return;
            }

            if ($method === 'GET') {
                $query = $request->GET['query'] ?? '';
                $request->respond($query);
                return;
            }

            if ($method === 'POST') {
                $query = $request->POST['query'] ?? '';
                $request->respond($query);
            }
        });

        $server->listen();

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->httpGet();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpLargePost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        \gc_collect_cycles();
        $baseMemory = \memory_get_usage();

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->httpGet();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpLargePost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        Guzzle::getInstance()
            ->getHttpClient()
            ->getConnectionPool()
            ->clearConnectionPool();
        \gc_collect_cycles();

        if ($baseMemory !== \memory_get_usage()) {
            echo "\nThere may be a memory leak.\n";
        }

        $this->httpClient();
        cancelAll();
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpGet(): void
    {
        $hash     = \md5(\uniqid());
        $client   = Guzzle::newClient();
        $response = $client->get('http://127.0.0.1:8008/', [
            'query' => [
                'query' => $hash,
            ]
        ]);

        $result = $response->getBody()->getContents();
        $this->assertEquals($hash, $result);
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpPost(): void
    {
        $hash     = \md5(\uniqid());
        $client   = Guzzle::newClient();
        $response = $client->post('http://127.0.0.1:8008/', [
            'json'    => [
                'query' => $hash,
            ],
            'timeout' => 1
        ]);

        $this->assertEquals($hash, $response->getBody()->getContents());
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpFile(): void
    {
        $client = Guzzle::newClient();
        $path   = \tempnam(\sys_get_temp_dir(), 'test');
        \file_put_contents($path, \str_repeat('a', 81920));
        $hash = \md5_file($path);
        $client->post('http://127.0.0.1:8008/upload', [
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => \fopen($path, 'r'),
                    'filename' => 'test.txt',
                ],
                [
                    'name'     => 'hash',
                    'contents' => $hash
                ]
            ],
            'timeout'   => 10,
            'sink'      => $path . '.bak'
        ]);
        $this->assertEquals($hash, \md5_file($path . '.bak'));
    }

    /**
     * Tests POST request with large data
     *
     * @return void
     * @throws GuzzleException
     */
    private function httpLargePost(): void
    {
        $largeData = \str_repeat('a', 1024 * 1024); // 1MB of data
        $hash      = \md5($largeData);
        $client    = Guzzle::newClient();
        $response  = $client->post('http://127.0.0.1:8008/', [
            'json'    => [
                'query' => $largeData
            ],
            'timeout' => 30 // Increased timeout for large data
        ]);

        $this->assertEquals($largeData, $response->getBody()->getContents());
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 10:01
     * @return void
     */
    private function httpClient(): void
    {
        $urls = [
            'https://www.baidu.com/',
            'https://www.qq.com/',
            'https://www.zhihu.com/',
            'https://www.taobao.com/',
            'https://www.jd.com/',
            'https://www.163.com/',
            'https://www.sina.com.cn/',
            'https://www.sohu.com/',
            'https://www.ifeng.com/',
            'https://juejin.cn/',
            'https://www.csdn.net/',
            'https://www.cnblogs.com/',
            'https://business.oceanengine.com/login',
            'https://www.laruence.com/',
            'https://www.php.net/',
            'https://www.abc.net/',
            'https://www.491e5d73fbeb64e8e7d66b25cb3d1823.net/',
            'http://www.491e5d73fbeb64e8e7d66b25cb3d1823.net/',
        ];

        $list = [];
        foreach ($urls as $url) {
            $list[] = async(function () use ($url) {
                try {
                    return [$url, Guzzle::newClient()->get($url, ['timeout' => 10])];
                } catch (Throwable $exception) {
                    return [$url, $exception];
                }
            })->except(function () {
                \var_dump(1);
                die;
            });
        }

        echo \PHP_EOL;
        foreach (Promise::futures($list) as $result) {
            if ($result instanceof Throwable) {
                echo "{$result->getMessage()} \n";
                continue;
            }
            [$url, $response] = $result;
            if ($response instanceof Throwable) {
                echo("{$url} {$response->getMessage()}\n");
            } else {
                echo("$url {$response->getStatusCode()}\n");
            }
        }
        echo \PHP_EOL;
    }
}
