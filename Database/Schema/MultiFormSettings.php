<?php

namespace Lightning\Database\Schema;

use Lightning\Database\Schema;

class MultiFormSettings extends Schema {
    const TABLE = 'multiform_settings';

    public function getColumns() {
        return [
            'id' => $this->autoincrement(),
            'url' => $this->varchar(255),
            'settings' => $this->text(),
        ];
    }

    public function getKeys() {
        return [
            'primary' => 'id',
            'url' => [
                'columns' => ['url'],
                'unique' => true,
            ],
        ];
    }
}
