<?php

namespace julio101290\boilerplatebackup\Models;

use CodeIgniter\Model;

class BackupsModel extends Model {

    protected $table = 'backups';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $allowedFields = ['id', 'idEmpresa', 'description', 'SQLFile', 'uuid', 'created_at', 'updated_at', 'deleted_at'];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $deletedField = 'deleted_at';
    protected $validationRules = [
    ];
    protected $validationMessages = [];
    protected $skipValidation = false;

    public function mdlGetBackups($idEmpresas) {
        $result = $this->db->table('backups as a')
                ->select('a.id as id
                ,a.idEmpresa as idEmpresa
                ,a.description as description
                ,a.SQLFile as SQLFile
                ,a.uuid as uuid
                ,a.created_at as created_at
                ,a.updated_at as updated_at
                ,a.deleted_at as deleted_at
                ,b.nombre as nombreEmpresa')
                ->join('empresas as b', 'a.idEmpresa = b.id')
                ->whereIn('a.idEmpresa', $idEmpresas);

        return $result;
    }
}
