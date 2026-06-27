<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\SalesModel;

class TestDb extends Controller
{
    public function index()
    {
        $model = new SalesModel();

        $data = $model->getSalesSummary();

        return $this->response->setJSON($data);
    }
}