<?php

namespace App\Models;

use Core\Models\Model;

class Nas extends Model
{
    protected const TABLE = 'app_nas';

    public function list(): ?array
    {
        try {
            $result = $this->db->table(self::TABLE)->get();
        } catch (\Throwable $th) {
            $result = [];
        }
        return $result;
    }
    public function add(array $data)
    {
        $result = $this->db->insert(
            table: self::TABLE,
            data: [
                'name' => $data['name'],
                'ip_address' => $data['ip_address'],
                'zone_name' => $data['zone_name'],
                'port' => $data['port'],
            ],
        );
        return $result;
    }
}
