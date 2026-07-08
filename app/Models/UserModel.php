<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 사용자(테넌트) 모델.
 */
class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;

    /** @var list<string> */
    protected $allowedFields = ['email', 'password_hash', 'name', 'is_active'];

    /** @var array<string, string> */
    protected $validationRules = [
        'email'         => 'required|valid_email|max_length[255]|is_unique[users.email,id,{id}]',
        'password_hash' => 'required|max_length[255]',
        'name'          => 'permit_empty|max_length[100]',
    ];
}
