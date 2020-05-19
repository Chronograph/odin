<?php

namespace App\Checkers;

use App\Website;
use App\UptimeScan;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use GuzzleHttp\RequestOptions;
use App\Notifications\WebsiteIsDown;
use App\Notifications\WebsiteIsBackUp;

class Uptime
{
    private $website;

    public function __construct(Website $website)
    {
        $this->website = $website;
    }

    public function run()
    {
        $this->fetch();
        $this->notify();
        $this->cache();
    }

    private function fetch()
    {
        $client = new Client();

        $response_time = 3001;
        $keywordFound = false;

        try {
            $response = $client->request('GET', $this->website->url, [
                RequestOptions::ON_STATS => function ($stats) use (&$response_time) {
                    $response_time = $stats->getTransferTime();
                },
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::VERIFY => false,
                RequestOptions::ALLOW_REDIRECTS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => config('app.user_agent'),
                ],
                RequestOptions::CONNECT_TIMEOUT => 20,
                RequestOptions::READ_TIMEOUT => 20,
                RequestOptions::TIMEOUT => 60,
                RequestOptions::DEBUG => false,
            ]);

            $keywordFound = Str::contains($response->getBody(), $this->website->uptime_keyword);

            if (!$keywordFound && $response->getStatusCode() == '200') {
                $reason = sprintf('Keyword: %s not found (%d)', $this->website->uptime_keyword, 200);
            } else {
                $reason = sprintf('%s (%d)', $response->getReasonPhrase(), $response->getStatusCode());
            }
        } catch (\Exception $exception) {
            $reason = $exception->getMessage();
        }

        $scan = new UptimeScan([
            'response_status' => $reason,
            'response_time' => $response_time,
            'was_online' => $keywordFound,
        ]);

        $this->website->uptimes()->save($scan);
    }

    private function notify()
    {
        $lastTwo = $this->website->uptimes()->orderBy('created_at', 'DESC')->take(2)->get();

        if ($lastTwo->count() !== 2) {
            return null;
        }

        $now = $lastTwo->first();
        $previous = $lastTwo->last();

        if ($now->online && $previous->offline) {
            return $this->website->user->notify(
                new WebsiteIsBackUp($this->website)
            );
        } elseif ($now->offline && $previous->online) {
            return $this->website->user->notify(
                new WebsiteIsDown($this->website)
            );
        }
    }

    private function cache()
    {
        $this->website->generateUptimeReport(true);
    }
}
