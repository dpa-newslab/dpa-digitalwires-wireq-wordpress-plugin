<?php
/**  -*- coding: utf-8 -*-
*
* Copyright 2022, dpa-IT Services GmbH
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*    http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.

* Plugin Name: dpa-digitalwires-to-wordpress
* Description: Import dpa-articles using the wireQ-api
* Version: 1.1.0
* Requires at least: 5.0
*/

//If this file is called directly, abort.
if (!defined('WPINC')){
    die;
}

define('PLUGIN_NAME_VERSION', '1.1.0');

if(!class_exists('DpaDigitalwires_Plugin')){
    class DpaDigitalwires_Plugin{
        private $admin_page;
        private $api;
        private $converter;

        public function __construct(){
            add_action('init', array($this, 'setup'));
        
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }

        public function setup(){
            $this->load_dependencies();
            $this->register_settings();

            add_action('update_option_dpa-digitalwires', array($this, 'update_settings'));
            add_filter('cron_schedules', array($this, 'dw_schedule'));
            add_action('dpa_digitalwires_cron', array($this, 'import_articles'));
        }

        public function deactivate(){
            $next_dw_task = wp_next_scheduled('dpa_digitalwires_cron');
            wp_unschedule_event($next_dw_task, 'dpa_digitalwires_cron');

            unregister_setting('dpa-digitalwires', 'dpa-digitalwires');
            delete_option('dpa-digitalwires');
            delete_option('dw_stats');
        
            error_log('dpa-digitalwires-Plugin deactivated');
        }

        public function dw_schedule($schedules){
            $config = get_option('dpa-digitalwires');

            $schedules['digitalwires-schedule'] = array(
                'interval' => $config['dw_cron_time']*60,
                'display' => 'Every ' . $config['dw_cron_time'] . ' minutes'
            );

            return $schedules;
        }

        public function update_settings(){
            error_log('dpa-digitalwires-Plugin settings updated');

            $config = get_option('dpa-digitalwires');
            
            $next_dw_task = wp_next_scheduled('dpa_digitalwires_cron');
            if($config['dw_active'] === true){
                $this->api = new DigitalwiresAPI($config['dw_endpoint']);

                if($next_dw_task){
                    wp_schedule_event($next_dw_task, 'digitalwires-schedule', 'dpa_digitalwires_cron');
                    error_log('Rescheduled dpa-digitalwires-wireQ-cron');
                }else{
                    wp_schedule_event(time(), 'digitalwires-schedule', 'dpa_digitalwires_cron');
                    error_log('Added dpa-digitalwires-wireQ-cron');
                }
            }elseif($next_dw_task){
                error_log('Removed dpa-digitalwires-wireQ-cron');
                wp_unschedule_event($next_dw_task, 'dpa_digitalwires_cron');
            }
        }

        private function register_settings(){
            register_setting('dpa-digitalwires', 'dpa-digitalwires', array(
                'default' => array(
                    'dw_endpoint' => null,
                    'dw_cron_time' => 5,
                    'dw_active' => false,
                ),
                'sanitize_callback' => array($this, 'validate_input')
            ));

            add_option('dw_stats', array(
                'last_run' => '-',
                'last_import_title' => null,
                'last_import_urn' => null,
                'last_import_timestamp' => null,
                'last_exception_message' => null,
                'last_exception_urn' => null,
                'last_exception_timestamp' => null
            ));
        }

        public function validate_input($input){
            if(! isset( $_POST['_wpnonce'] ) || 
            ! wp_verify_nonce( $_POST['_wpnonce'], 'dpa-digitalwires-options' )){
                add_settings_error('dpa-digitalwires', 'invalid_nonce', 'Formular-Validierung fehlgeschlagen', 'error');
            }

            $current_config = get_option('dpa-digitalwires');
            $output = array(
                'dw_endpoint' => $this->validate_endpoint($input['dw_endpoint'], $current_config['dw_endpoint']),
                'dw_cron_time' => $this->validate_cron_time($input['dw_cron_time'], $current_config['dw_cron_time'])
            );

            if(!empty($input['dw_active']) && !empty($output['dw_endpoint'])){                
                $output['dw_active'] = $this->validate_active($input['dw_active']);
            }else{
                $output['dw_active'] = false;
            }

            return apply_filters('dpa-digitalwires', $output, $input);
        }

        private function validate_cron_time($input, $old){
            if(empty($input)){
                add_settings_error('dpa-digitalwires', 'invalid_time', 'Abfragezyklus fehlt', 'error');
                return $old;
            }

            return $input;
        }

        private function validate_endpoint($input, $old){
            $endpoint;
            $valid = true;
            
            if(!empty($input) && substr($input, 0, 37) != 'https://digitalwires.dpa-newslab.com/'){
                $valid = false;
                add_settings_error('dpa-digitalwires', 'invalid_url', 'URL ist kein bekannter dpa-digitalwires-Endpunkt', 'error');
                return $old;
            }else{
                return esc_attr($input);
            }
        }

        private function validate_active($input){
            return apply_filters('dw_active', $input === 'on', $input);
        }

        private function load_dependencies(){
            require_once plugin_dir_path(__FILE__) . 'includes/api.php';

            $digitalwires_option = get_option('dpa-digitalwires');
            if(isset($digitalwires_option['dw_endpoint'])){
                $this->api = new DigitalwiresAPI($digitalwires_option['dw_endpoint']);
            }

            require_once plugin_dir_path(__FILE__) . '/includes/admin.php';
            $this->admin_page = new AdminPage();

            require_once plugin_dir_path(__FILE__) . '/includes/converter.php';
            $this->converter = new Converter();
        } 

        public function import_articles(){
            error_log('Fetching articles');
            
            $fetch_num = 0;
            $entries;

            $dw_stats = get_option("dw_stats");
            
            do{
                $fetch_num = $fetch_num + 1;
                $entries = ($this->api)->fetch_articles();
                
                foreach($entries as $entry){
                    try{
                        switch($entry['pubstatus']){
                            case 'usable':
                                $this->converter->add_post($entry);
                                break;
                            case 'canceled':
                                $this->converter->remove_post($entry);
                                break;
                        }

                        $dw_stats['last_import_title'] = $entry['headline'];
                        $dw_stats['last_import_urn'] = $entry['urn'];
                        $dw_stats['last_import_timestamp'] = $entry['version_created'];
                    }catch(Exception $e){
                        error_log($e);
                        $dw_stats['last_exception_message'] = $e->getMessage();
                        $dw_stats['last_exception_urn'] = $entry['urn'];
                        $dw_stats['last_exception_timestamp'] = date("d.m.Y, H:i:s T", strtotime($entry['version_created']));
                    }
                    $this->api->remove_from_queue($entry['_wireq_receipt']);
                }
            }while(!empty($entries));
            
            error_log('Called API ' . print_r($fetch_num, TRUE) . ' times');

            $tz = date_default_timezone_get();
            date_default_timezone_set(get_option('timezone_string'));
            
            $dw_stats['last_run'] = date('d.m.Y, H:i:s T');
            
            $dw_stats['last_import_timestamp'] = date("d.m.Y, H:i:s T", strtotime($dw_stats['last_import_timestamp']));
            
            date_default_timezone_set($tz);
            update_option('dw_stats', $dw_stats);
        }

    }

    $plugin = new DpaDigitalwires_Plugin();
}


?>