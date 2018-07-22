<?php

namespace Modules\MultiForm\Pages;

use Exception;
use Lightning\Model\User;
use Lightning\Tools\Database;
use Lightning\Tools\Navigation;
use Lightning\Tools\Output;
use Lightning\Tools\Request;
use Lightning\Tools\Scrub;
use Lightning\Tools\Session\BrowserSession;
use Lightning\Tools\Template;
use Lightning\View\Page;

class MultiForm extends Page {

    protected $state;
    protected $formId;
    protected $route;

    protected $page = ['multiform', 'MultiForm'];

    public function __construct() {
        parent::__construct();
        $this->route = Request::getLocation();
        $this->loadState();
    }

    public function hasAccess() {
        return true;
    }

    public function get() {
        $form = $this->getCurrentForm();

        if (!empty($form['redirect'])) {
            $this->state[$this->route]['form'] ++;
            $this->saveState();
            Navigation::redirect($form['redirect']);
        }

        if (empty($form['action'])) {
            $form['action'] = $this->route;
        }
        $form['method'] = 'POST';
        $form['validate'] = true;

        // Add the submit button
        // TODO: This should check if there is already one or multiple submit buttons
        $form['fields'][] = [
            'type' => 'submit',
            'value' => 'Continue',
        ];

        // Add the hidden form-id field at the end
        $form['fields'][] = [
            'type' => 'hidden',
            'name' => 'form-id',
            'value' => $this->formId,
        ];
        Template::getInstance()->set('form', $form);

        // Add page attributes
        $settings = $this->getFormSettings();
        if (!empty($settings['page'])) {
            foreach ($settings['page'] as $key => $val) {
                $this->$key = $val;
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function post() {
        $this->state[$this->route]['form'] = Request::post('form-id', Request::TYPE_INT);
        $form = $this->getCurrentForm();

        // Validate the form and get the values.
        $values = $this->validateForm($form);

        // Save the form data.
        $this->saveData($form, $values);

        // Advance to the next form.
        $this->state[$this->route]['form'] ++;
        $this->saveState();
        Navigation::redirect();
    }

    /**
     * @param $form
     * @param $data
     * @throws \Exception
     */
    protected function saveData($form, $data) {

        $previousSaveQueue = [];
        $saveQueue = $this->getSaveQueue($form, $data);

        while (json_encode($previousSaveQueue) != json_encode($saveQueue)) {
            // Save the queue. This will update any id references in $this->state.
            $this->saveQueue($saveQueue);

            // Check to see if the save queue has changed. There might be new data
            // that depends on primary keys inserted by another storage method.
            $previousSaveQueue = $saveQueue;
            $saveQueue = $this->getSaveQueue($form, $data);
        }
    }

    protected function getSaveQueue($form, $data) {
        // Organize the posted data into where it's going.
        $saveQueue = [];
        foreach ($form['fields'] as $field) {
            $storage = $field['storage'];
            switch ($storage['type']) {
                case 'mysql':
                    $saveQueue['mysql'][$storage['table']][$storage['column']] = $data[$field['name']];
                    break;
                case 'mysql_json':
                    $saveQueue['mysql'][$storage['table']][$storage['column']][$storage['json_path']] = $data[$field['name']];
                    break;
                case 'user':
                    $saveQueue['user'][$storage['field']] = $data[$field['name']];
                    break;
            }
        }

        // Update associations
        // This has to take place after saving the data, because these associations may require the unique
        // ID created on insert by the previous save.
        // Entries here should only be created if the data is not already present.
        $storage = $this->getFormSettings()['storage'];
        foreach ($storage as $type => $containers) {
            switch ($type) {
                case 'mysql':
                    foreach ($containers as $table => $table_storage) {
                        foreach ($table_storage['fields'] as $column => $settings) {
                            switch ($settings['storage']) {
                                case 'user':
                                    // TODO: Add case if a user is already signed in.
                                    if (isset($this->state[$this->route]['user'][$settings['field']])) {
                                        // TODO: add json_path option here
                                        $saveQueue['mysql'][$table][$column] = $this->state[$this->route]['user'][$settings['field']];
                                    }
                                    break;
                            }
                        }
                    }
                    break;
            }
        }

        return $saveQueue;
    }

    /**
     * @param $saveQueue
     * @throws \Exception
     */
    protected function saveQueue($saveQueue) {
        // Save each item into the database
        foreach ($saveQueue as $queue => $data) {
            switch ($queue) {
                case 'mysql':
                    foreach ($data as $table => $fields) {
                        if (empty($this->state[$this->route]['mysql'][$table]['row_id'])) {
                            // If we don't know of an existing row, insert one.
                            $insert = [];
                            foreach ($fields as $column => $value) {
                                if (is_array($value)) {
                                    $insert[$column] = json_encode($value);
                                } else {
                                    $insert[$column] = $value;
                                }
                            }
                            $this->state[$this->route]['mysql'][$table]['row_id'] = Database::getInstance()->insert($table, $insert);
                        } else {
                            $insert = [];
                            $current_entry = null;
                            $primary_key = $this->getFormSettings()['storage']['mysql'][$table]['primary_key'];
                            foreach ($fields as $column => $value) {
                                if (is_array($value)) {
                                    if (empty($current_entry)) {
                                        // TODO: Get PK column for this
                                        $current_entry = Database::getInstance()->selectRow($table, [$primary_key => $this->state[$this->route]['mysql'][$table]['row_id']]);
                                    }
                                    $insert[$column] = json_encode($value + json_decode($current_entry[$column], true));
                                } else {
                                    $insert[$column] = $value;
                                }
                            }

                            // If we know of an existing row, update it.
                            // TODO: Get PK column for this
                            Database::getInstance()->update($table, $insert, [$primary_key => $this->state[$this->route]['mysql'][$table]['row_id']]);
                        }
                    }
                    break;
                case 'user':
                    if ($email = Scrub::email($data['email'])) {
                        $user = User::addUser($email);
                        $this->state[$this->route]['user']['user_id'] = $user->id;
                    }
                    if (!empty($data['list_id'])) {
                        $user->subscribe($data['list_id']);
                    }
                    break;
            }
        }
    }

    /**
     * @param $form
     * @return array
     * @throws \Exception
     */
    protected function validateForm($form) {
        foreach ($form['fields'] as $field) {
            $value = Request::post($field['name']);
            if (empty($value) && !empty($field['required'])) {
                throw new \Exception('Invalid request');
            }

            // Set the value
            if (empty($value) && !empty($field['value'])) {
                $values[$field['name']] = $field['value'];
            } else {
                $values[$field['name']] = $value;
            }
        }

        return $values;
    }

    protected function loadState() {
        // Load from the cookie session
        $this->state = BrowserSession::getInstance()->multiform;

        // If there is nothing, create a start state
        // if the state indicates a form that doesn't exist, reset it.
        if (empty($this->state[$this->route]) || $this->state[$this->route]['form'] >= count($this->getFormSettings()['forms'])) {
            $this->state[$this->route] = ['form' => 0];
        }

        // Set the form ID
        $this->formId = $this->state[$this->route]['form'];
    }

    protected function saveState() {
        $browserSession = BrowserSession::getInstance();
        $browserSession->multiform = $this->state;
        $browserSession->save();
    }

    protected function getCurrentForm() {
        return $this->getFormSettings()['forms'][$this->formId];
    }

    /**
     * @return mixed
     *
     * @throws Exception
     */
    protected function getFormSettings() {
        if ($settings = Database::getInstance()->selectField('settings', 'multiform_settings', ['url' => $this->route])) {
            if ($decoded = json_decode($settings, true)) {
                return $decoded;
            } else {
                throw new Exception('Invalid settings');
            }
        } else {
            Output::notFound();
        }
    }
}
