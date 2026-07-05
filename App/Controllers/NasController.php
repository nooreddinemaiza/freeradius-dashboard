<?php

namespace App\Controllers;

use App\Models\Nas;
use Core\Controllers\Controller;
use Core\Exception\ValidationException;
use Core\Helper\Data;
use Throwable;

class NasController extends Controller
{
    private Nas $model;
    public function __construct()
    {
        $this->model = new Nas();
    }
    /**
     * Liste des clients NAS
     */
    public function list()
    {
        return $this->model->list();
    }
    public function add(array $data)
    {
        try {
            $data = (new Data($data))->only([
                'name',
                'ip_address',
                'zone_name',
                'port'
            ]);
            $errors = $data->validate([
                'name'       => 'required|string|max:253',
                'ip_address' => 'required|string|max:253',
                'zone_name'  => 'required|string|max:253',
                'port'       => 'numeric'
            ]);
            if ($errors) {
                throw new ValidationException(errors:$errors);
            }
            $result =  $this->model->add($data->toArray());
            return $result ? true : false;
        } catch (\Exception | \Throwable $e) {
            return false;
        }
    }
}
