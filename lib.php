<?php
/**
 * @package portfolio_wordpress
 * @copyright 2014 Institut Obert de Catalunya
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author Albert Gasset <albert@ioc.cat>
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');

class portfolio_plugin_wordpress extends portfolio_plugin_push_base {

    private $client;

    public function supported_formats() {
        return array(
            PORTFOLIO_FORMAT_RICHHTML,
            PORTFOLIO_FORMAT_PLAINHTML,
        );
    }

    public function expected_time($callertime) {
        // We're forcing this to be run 'interactively' because the plugin
        // does not support running in cron.
        return PORTFOLIO_TIME_LOW;
    }

    public static function get_name() {
        return get_string('pluginname', 'portfolio_wordpress');
    }

    public static function has_admin_config() {
        return true;
    }

    public function has_export_config() {
        return true;
    }

    public function get_export_summary() {
        $strpublish = get_string('publishposts', 'portfolio_wordpress');
        $publish = $this->get_export_config('publish');
        return array($strpublish => get_string('publishposts' . $publish, 'portfolio_wordpress'));
    }

    public function prepare_package() {
        // We send the files as they are, no prep required.
        return true;
    }

    public function send_package() {
        if (!$this->client or !$this->client->is_logged_in()) {
            throw new portfolio_plugin_exception('noauthtoken', 'portfolio_wordpress');
        }

        $publish = ($this->get_export_config('publish') == 'yes');

        $tmpdir = $this->make_temp_dir();
        $files = $this->exporter->get_tempfiles();

        foreach ($files as $file) {
            if ($file->get_mimetype() != 'text/html') {
                continue;
            }
            $title = '';
            $date = false;
            $content = $file->get_content();
            if (preg_match('/<title>(.*?)<\/title>/s', $content, $matches)) {
                $title = $matches[1];
            }
            if (preg_match('/<meta\s+property="dc:created"\s+content="(.*?)"/s', $content, $matches)) {
                $date = $matches[1];
            }
            if (preg_match('/<body>(.*)<\/body>/s', $content, $matches)) {
                $content = $matches[1];
            }
            $media = array();

            if (preg_match_all('/src="site_files\/(.*?)"/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $filename = urldecode($match[1]);
                    $path = '/site_files/' . $filename;
                    if (isset($files[$path]) and $files[$path]->is_valid_image()) {
                        $tmppath = $tmpdir . '/' . $filename;
                        $files[$path]->copy_content_to($tmppath);
                        $url = $this->client->new_media('@'.$tmppath);
                        if ($url) {
                            $content = str_replace($match[0], 'src="'.s($url).'"', $content);
                        }
                    }
                }
            }
            $this->client->new_post($title, $content, $date, $publish);
        }

        remove_dir($tmpdir);

        return true;
    }

    public function get_interactive_continue_url() {
        if ($this->client and $this->client->is_logged_in()) {
            $accesstoken = $this->client->get_accesstoken();
            $url = $accesstoken->blog_url;
            if ($this->get_export_config('publish') != 'yes') {
                $url .= '/wp-admin/edit.php';
            }
            return $url;
        } else {
            return 'http://wordpress.com/';
        }
    }

    public static function get_allowed_config() {
        return array('clientid', 'clientsecret', 'restapiurl', 'authorizeurl', 'tokenurl');
    }

    public function get_allowed_export_config() {
        return array('publish');
    }

    public static function admin_config_form(&$mform) {
        $a = new stdClass;
        $a->registerurl = "https://developer.wordpress.com/apps/new/";
        $a->callbackurl = wordpress_client::callback_url()->out(false);

        $mform->addElement('static', null, '', get_string('oauthinfo', 'portfolio_wordpress', $a));

        $mform->addElement('text', 'clientid', get_string('clientid', 'portfolio_wordpress'));
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->addRule('clientid', get_string('required'), 'required', null, 'client');

        $strclientsecret = get_string('clientsecret', 'portfolio_wordpress');
        $mform->addElement('text', 'clientsecret', $strclientsecret, array('size' => 80));
        $mform->setType('clientsecret', PARAM_RAW_TRIMMED);
        $mform->addRule('clientsecret', get_string('required'), 'required', null, 'client');

        $strrestapiurl = get_string('restapiurl', 'portfolio_wordpress');
        $mform->addElement('text', 'restapiurl', $strrestapiurl, array('size' => 80));
        $mform->setType('restapiurl', PARAM_RAW_TRIMMED);
        $mform->addRule('restapiurl', get_string('required'), 'required', null, 'client');
        $mform->setDefault('restapiurl' ,'https://public-api.wordpress.com/rest/v1');

        $strauthorizeurl = get_string('authorizeurl', 'portfolio_wordpress');
        $mform->addElement('text', 'authorizeurl', $strauthorizeurl, array('size' => 80));
        $mform->setType('authorizeurl', PARAM_RAW_TRIMMED);
        $mform->addRule('authorizeurl', get_string('required'), 'required', null, 'client');
        $mform->setDefault('authorizeurl', 'https://public-api.wordpress.com/oauth2/authorize');

        $strtokenurl = get_string('tokenurl', 'portfolio_wordpress');
        $mform->addElement('text', 'tokenurl', $strtokenurl, array('size' => 80));
        $mform->setType('tokenurl', PARAM_RAW_TRIMMED);
        $mform->addRule('tokenurl', get_string('required'), 'required', null, 'client');
        $mform->setDefault('tokenurl', 'https://public-api.wordpress.com/oauth2/token');
    }

    public function export_config_form(&$mform) {
        $strpublish = get_string('publishposts', 'portfolio_wordpress');
        $options = array(
            'yes' => get_string('publishpostsyes', 'portfolio_wordpress'),
            'no' => get_string('publishpostsno', 'portfolio_wordpress'),
        );
        $mform->addElement('select', 'plugin_publish', $strpublish, $options);
        $mform->setType('plugin_publish', PARAM_RAW);
    }

    public function steal_control($stage) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }
        $this->initialize_client();
        if ($this->client->is_logged_in()) {
            return false;
        } else {
            return $this->client->get_login_url();
        }
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }
        if (!$this->client->is_logged_in()) {
            throw new portfolio_plugin_exception('noauthtoken', 'portfolio_wordpress');
        }
    }

    private function initialize_client() {
        if (!empty($this->client)) {
            return;
        }
        $returnurl = new moodle_url('/portfolio/add.php');
        $returnurl->param('postcontrol', 1);
        $returnurl->param('id', $this->exporter->get('id'));
        $returnurl->param('sesskey', sesskey());
        $this->client = new wordpress_client(
            $this->get_config('clientid'),
            $this->get_config('clientsecret'),
            $this->get_config('authorizeurl'),
            $this->get_config('tokenurl'),
            $this->get_config('restapiurl'),
            $returnurl);
    }

    private function make_temp_dir() {
        $path = make_temp_directory('portfolio_wordpress/' . $this->exporter->get('id'));
        if (!$path) {
            throw new portfolio_plugin_exception('cannotcreatetempdir');
        }
        return $path;
    }

}

class wordpress_client extends oauth2_client {

    private $authorizeurl;
    private $tokenurl;
    private $restapiurl;

    public function __construct($clientid, $clientsecret, $authorizeurl, $tokenurl,
                                $restapiurl, moodle_url $returnurl) {
        parent::__construct($clientid, $clientsecret, $returnurl, '');
        $this->authorizeurl = $authorizeurl;
        $this->tokenurl = $tokenurl;
        $this->restapiurl = $restapiurl;
    }

    protected function auth_url() {
        return $this->authorizeurl;
    }

    protected function token_url() {
        return $this->tokenurl;
    }

    public function reset_state() {
        $this->cleanopt();
        $this->resetHeader();
    }

    public function upgrade_token($code) {
        $callbackurl = self::callback_url();
        $params = array('client_id' => $this->get_clientid(),
                        'client_secret' => $this->get_clientsecret(),
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'redirect_uri' => $callbackurl->out(false));

        $response = $this->post($this->token_url(), $params);

        if (!$this->info['http_code'] === 200) {
            throw new moodle_exception('Could not upgrade oauth token');
        }

        $r = json_decode($response);

        if (!isset($r->access_token)) {
            return false;
        }

        $accesstoken = new stdClass;
        $accesstoken->token = $r->access_token;
        $accesstoken->blog_id = $r->blog_id;
        $accesstoken->blog_url = $r->blog_url;
        $this->store_token($accesstoken);

        return true;
    }

    private function api_request($endpoint, $params=null) {
        $this->resetHeader();
        $blog_id = $this->get_accesstoken()->blog_id;
        $url = $this->restapiurl . '/sites/' . $blog_id . '/' . $endpoint;
        if ($params) {
            $res = $this->post($url, $params);
        } else {
            $res = $this->get($url);
        }
        return json_decode($res, true);
    }

    public function new_post($title, $content, $date=false, $publush=false) {
        $params = array(
            'title' => $title,
            'content' => $content,
            'status' => $publush ? 'publish' : 'draft',
        );
        if ($date !== false) {
            $params['date'] = $date;
        }
        $res = $this->api_request('posts/new', $params);
        if (!empty($res['URL'])) {
            return $res['URL'];
        }
    }

    public function new_media($file) {
        $params = array('media[]' => $file);
        $res = $this->api_request('media/new', $params);
        if (!empty($res['media'][0]['link'])) {
            return $res['media'][0]['link'];
        }
    }
}
