<?php

/* Controller used in a old symfony project.
 * I will refactor to work correctly in this new project.
 */

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WPOParseCommand extends Command
{
    protected static $defaultName = 'wpo:parse';

    protected function configure(): void
    {
        $this->setHelp('This command parse the actual wpo values from all webs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /**
         * Config
         */
        $updateFields = false;
        $config = json_decode(file_get_contents('config/config.json'), true);

        $try_max = 3;
        $strategy = 'mobile';
        $id_cron = md5(time());

        /**
         * Parse URLs
         */

        $host = isset($argv[1]) ? $argv[1] : "all";
        $allUrls = file("config/urls.txt");

        if ($host == 'all') {
            $urls = $allUrls;
        } else {
            if (!isset($config['hosts'][$host])) {
                $this->clog('host '.$host.' not configured');
                die();
            }

            $domains = $config['hosts'][$host]["domains"];
            $urls = [];

            foreach ($allUrls as $item) {
                $segments = explode("/", $item);
                if (isset($segments[2]) && in_array($segments[2], $domains)) {
                    $urls[] = $item;
                }
            }
        }

        $scores = ['_timestamp' => time()];

        foreach($urls as $n => $url) {
            $url = trim($url);
            $try_n = 0;
            $try_res = false;

            $this->clog('> process url '.$n.' '.(is_array($url) ? $url[0] : $url));

            /*
             * Get pagespeed data
             */
            if (is_array($url)) $url = $url[0];

            while($try_n <= $try_max) {
                if ($try_res) break;
                $try_n++;
                $this->clog('  get pagespeed (#'.$try_n.') ...');
                $values = $this->getApi($url, $config['api']['google'], $strategy);

                /**
                 * Create json with result structure in fields.sample.json
                 */
                if ($updateFields) {
                    $fields = getOnlyFields($values);
                    file_put_contents('fields.sample.json', json_encode($fields));
                    $updateFields = false;
                }

                /**
                 * Add other call values
                 */
                $values['%call'] = [
                    'url' => $url,
                    'strategy' => $strategy,
                    'psi_status' => "Success",
                    'psi_error' => "",
                    'id_cron' => $id_cron
                ];

                /**
                 * Get call values
                 */
                $insert = [];
                $this->parseFields($config['fields'], $values, $insert);

                foreach($insert as $key => $value) {
                    switch ($key) {
                        case 'Address':
                        case 'PSI Status':
                        case 'PSI Error':
                            break;
                        case 'Performance Score':
                            $insert[$key] = (float)$insert[$key] * 100;
                            if (!$value) $insert[$key] = '0';
                            break;
                        default:
                            if (!$value) $insert[$key] = '0';
                    }
                }

                $scores[$url] = (int)$insert['Performance Score'];
                $insert['id_cron'] = $id_cron;

                if ($scores[$url]) {
                    // $db->insert($config['database']['table'], $insert);
                    $try_res = true;
                }
            }
        }

        $this->clog('END!!');
        
        return Command::SUCCESS;
    }

    private function clog($text) {
        print $text."\r\n";
    }

    private function processPageHTML($url) {
        $debug = true;
        $parseJS = true;
        $parseCSS = true;
        $parseIMG = true;

        $this->clog('  get HTML & Resources ...');
        $times = [microtime(true)];
        $targetURL = is_array($url) ? $url[0] : $url;
        preg_match('|(https?:\/\/.*?)\/|', $targetURL.'/', $result);
        $host = $result[0];

        // (1) HTML
        $html = file_get_contents($targetURL);
        $times[] = microtime(true) - $times[0];

        // (2) CSS
        if ($parseCSS) {
            preg_match_all('|href=\"(https?:\/\/)?(.*?)\/(\w*\.css)\"|', $html, $results);
            //var_dump($results[0]); die();
            if (count($results[0])) {

                foreach ($results[0] as $item) {
                    $item = str_replace(["href=", '"'], ['', ''], $item);
                    if ($debug) $this->clog('->'.$item);
                    if (substr($item,0,1) == '/') {
                        $trash = file_get_contents($host.$item);
                    } else {
                        $trash = file_get_contents($item);
                    }
                }
            }
            $times[] = microtime(true) - $times[0];
            $this->clog('  * CSS ... = '.$times[count($times)-1]);
        } else {
            $times[] = 0;
        }

        // (3) JS
        if ($parseJS) {
            preg_match_all('|src=\"(https?:\/\/)?(.*?)\/(\w*\.js)\"|', $html, $results);
            if (count($results[0])) {
                foreach ($results[0] as $item) {
                    $item = str_replace(["src=", '"'], ['', ''], $item);
                    if ($debug) $this->clog('->'.$item);
                    if (substr($item,0,1) == '/') {
                        $trash = file_get_contents($host.$item);
                    } else {
                        $trash = file_get_contents($item);
                    }
                }
            }
            $times[] = microtime(true) - $times[0];
            $this->clog('  * JS ...  = '.$times[count($times)-1]);
        } else {
            $times[] = 0;
        }

        // (4) IMAGES
        if ($parseIMG) {
            preg_match_all('|src=\"(.*?)\"|', $html, $results);
            if (count($results[0])) {
                foreach ($results[0] as $item) {
                    $item = str_replace(["src=", '"'], ['', ''], $item);
                    $item = trim($item);
                    $temp = explode('.', $item);
                    if (count($temp) > 1) {
                        if (in_array(strtolower($temp[count($temp)-1]), ['png', 'jpg', 'jpeg', 'bmp', 'gif', 'webm', 'webp'])) {
                            if ($debug) $this->clog('->'.$item);
                            if (substr($item,0,1) == '/') {
                                $trash = file_get_contents($host.$item);
                            } else {
                                $trash = file_get_contents($item);
                            }
                        }
                    }
                }
            }
            $times[] = microtime(true) - $times[0];
            $this->clog('  * IMG ... = '.$times[count($times)-1]);
        } else {
            $times[] = 0;
        }

        // (5) OTHER ITEMS IN URL ARRAY
        if (is_array($url)) {
            for($i=1; $i<count($url); $i++) {
                $item = $url[$i];
                if ($debug) $this->clog('->'.$item);
                if (substr($item,0,1) == '/') {
                    $trash = file_get_contents($host.$item);
                } else {
                    $trash = file_get_contents($item);
                }
            }
            $times[] = microtime(true) - $times[0];
            $this->clog('  * OTH ... = '.$times[count($times)-1]);
        } else {
            $times[] = 0;
        }

        return $times;
    }

    private function parseFields($fields, $values, &$insert) {
        $result = [];

        if (is_array($fields)) {
            foreach($fields as $key => $value) {
                $actualValues = isset($values[$key]) ? $values[$key] : null;

                if (is_array($value)) {
                    $result[$key] = $this->parseFields($value, $actualValues, $insert);
                } else {
                    $insert[$value] = $actualValues;
                }
            }
        }

        return $result;
    }

    private function getOnlyFields($fields) {
        $result = [];

        if (is_array($fields)) {
            foreach($fields as $key => $value) {
                if (is_array($value)) {
                    $result[$key] = getOnlyFields($value);
                } else {
                    $result[$key] = null;
                }
            }
        }

        return $result;
    }

    private function getApi($url, $key, $strategy = '') {
        $url = trim($url);
        $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='.$url.'&key='.$key;
        if ($strategy == 'desktop') $strategy = '';
        if ($strategy) $apiUrl .= '&strategy='.$strategy;

        $data = $this->getUrl($apiUrl);
        $json = json_decode($data, true);

        return $json;
    }

    function getUrl($url) {
        $response = file_get_contents($url);
        return $response;
    }
}
