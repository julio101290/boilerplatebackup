<?php

namespace julio101290\boilerplatebackup\Controllers;

use App\Controllers\BaseController;
use julio101290\boilerplatebackup\Models\{
    BackupsModel
};
use julio101290\boilerplatelog\Models\LogModel;
use CodeIgniter\API\ResponseTrait;
use julio101290\boilerplatecompanies\Models\EmpresasModel;
use DatabaseBackupManager\MySQLBackup;
use PostgresqlBackupManager\PostgresqlBackupManager;
use PDO;

class BackupsController extends BaseController {

    use ResponseTrait;

    protected $log;
    protected $backups;

    public function __construct() {
        $this->backups = new BackupsModel();
        $this->log = new LogModel();
        $this->empresa = new EmpresasModel();
        helper('menu');
        helper('utilerias');
    }

    public function index() {



        helper('auth');

        $idUser = user()->id;
        $titulos["empresas"] = $this->empresa->mdlEmpresasPorUsuario($idUser);

        if (count($titulos["empresas"]) == "0") {

            $empresasID[0] = "0";
        } else {

            $empresasID = array_column($titulos["empresas"], "id");
        }




        if ($this->request->isAJAX()) {
            $datos = $this->backups->mdlGetBackups($empresasID);

            return \Hermawan\DataTables\DataTable::of($datos)->toJson(true);
        }
        $titulos["title"] = lang('backups.title');
        $titulos["subtitle"] = lang('backups.subtitle');
        return view('julio101290\boilerplatebackup\Views\backups', $titulos);
    }

    /**
     * Read Backups
     */
    public function getBackups() {

        helper('auth');

        $idUser = user()->id;
        $titulos["empresas"] = $this->empresa->mdlEmpresasPorUsuario($idUser);

        if (count($titulos["empresas"]) == "0") {

            $empresasID[0] = "0";
        } else {

            $empresasID = array_column($titulos["empresas"], "id");
        }


        $idBackups = $this->request->getPost("idBackups");
        $datosBackups = $this->backups->whereIn('idEmpresa', $empresasID)
                        ->where("id", $idBackups)->first();
        echo json_encode($datosBackups);
    }

    /**
     * Save or update Backups
     */
    public function save() {
        helper('auth');
        $userName = user()->username;
        $idUser = user()->id;
        $datos = $this->request->getPost();

        $dbConfig = config('Database')->default;

        $host = $dbConfig["hostname"];
        $dbname = $dbConfig["database"];
        $username = $dbConfig["username"];
        $password = $dbConfig["password"];
        $port = $dbConfig["port"];
        $driver = $dbConfig["DBDriver"] ?? 'MySQLi';

        $pdoDsn = '';
        $dbBackupConnection = null;
        $backupClass = null;

        try {
            if (in_array(strtolower($driver), ['mysqli', 'mysql', 'mysqlpdo', 'pdo_mysql'])) {
                $pdoDsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
                $dbBackupConnection = new \PDO($pdoDsn, $username, $password);
                $backup = new MySQLBackup($dbBackupConnection, ROOTPATH . "writable/database/backup");
            } elseif (in_array(strtolower($driver), ['pgsql', 'postgre', 'postgresql'])) {
                $pdoDsn = "pgsql:host=$host;port=$port;dbname=$dbname";
                $dbBackupConnection = new \PDO($pdoDsn, $username, $password);
                //$backupClass = new \PostgresqlBackupManager\PostgreSQLBackup($dbBackupConnection, ROOTPATH . "writable/database/backup");
                $backupFolder = ROOTPATH . "writable/database/backup";

                $backup = new \PostgresqlBackupManager\PostgreSQLBackup($dbBackupConnection, $dbname, $username, $password, $host, $port, $backupFolder);
            } else {
                throw new \Exception("Driver de base de datos no soportado para backups: $driver");
            }



            if (in_array(strtolower($driver), ['mysqli', 'mysql', 'mysqlpdo', 'pdo_mysql'])) {
                $backup = $backup->backup(false, true, false);
            } else {

                $backup = $backup->backup(true, true, true, false);
            }
        } catch (\Exception $ex) {
            echo "Error en backup: " . $ex->getMessage();
            return;
        }

        $datos["SQLFile"] = $backup;
        $datos["uuid"] = generaUUID();

        if (empty($datos["idBackups"]) || $datos["idBackups"] == 0) {
            try {
                if ($this->backups->save($datos) === false) {
                    $errores = $this->backups->errors();
                    foreach ($errores as $field => $error) {
                        echo $error . " ";
                    }
                    return;
                }
                $dateLog["description"] = lang("backups.logUpdated") . json_encode($datos);
                $dateLog["user"] = $userName;
                $this->log->save($dateLog);
                echo "Guardado Correctamente";
            } catch (\Exception $ex) {
                echo "Error al guardar: " . $ex->getMessage();
            }
        } else {
            try {
                if ($this->backups->update($datos["idBackups"], $datos) == false) {
                    $errores = $this->backups->errors();
                    foreach ($errores as $field => $error) {
                        echo $error . " ";
                    }
                    return;
                } else {
                    $dateLog["description"] = lang("backups.logUpdated") . json_encode($datos);
                    $dateLog["user"] = $userName;
                    $this->log->save($dateLog);
                    echo "Actualizado Correctamente";
                }
            } catch (\Exception $ex) {
                echo "Error al actualizar: " . $ex->getMessage();
            }
        }
    }

    /**
     * Delete Backups
     * @param type $id
     * @return type
     */
    public function delete($id) {
        $infoBackups = $this->backups->find($id);
        helper('auth');
        $userName = user()->username;
        if (!$found = $this->backups->delete($id)) {
            return $this->failNotFound(lang('backups.msg.msg_get_fail'));
        }
        $this->backups->purgeDeleted();

        unlink($infoBackups["SQLFile"]);
        $logData["description"] = lang("backups.logDeleted") . json_encode($infoBackups);
        $logData["user"] = $userName;
        $this->log->save($logData);
        return $this->respondDeleted($found, lang('backups.msg_delete'));
    }

    /**
     * Restore Backup (MariaDB o PostgreSQL)
     * @param string $uuid
     * @return ResponseInterface
     */

    /**
     * Restore Backup (MariaDB o PostgreSQL desde .zip)
     * @param string $uuid
     * @return ResponseInterface
     */
    public function restoreBackup($uuid) {
        $infoBackups = $this->backups->select("*")->where("uuid", $uuid)->first();

        if (!$infoBackups) {
            return $this->failNotFound("Respaldo no encontrado");
        }

        $allBackups = $this->backups->asArray()->findAll();

        helper('auth');
        $userName = user()->username;

        // Configuración de base de datos
        $dbConfig = config('Database')->default;
        $host = $dbConfig["hostname"];
        $dbname = $dbConfig["database"];
        $username = $dbConfig["username"];
        $password = $dbConfig["password"];
        $port = $dbConfig["port"];
        $dbDriver = strtolower($dbConfig["DBDriver"]);

        // Ruta del archivo
        $backupPath = $infoBackups["SQLFile"];

        if (!file_exists($backupPath)) {
            return $this->failNotFound("Archivo de respaldo no encontrado: $backupPath");
        }

        try {
            if ($dbDriver === 'postgre' || $dbDriver === 'pgsql') {
                // Si es PostgreSQL, descomprimir ZIP
                $zip = new \ZipArchive();
                if ($zip->open($backupPath) === TRUE) {
                    $extractPath = ROOTPATH . "writable/database/backup/tmp_" . uniqid();
                    mkdir($extractPath, 0755, true);
                    $zip->extractTo($extractPath);
                    $zip->close();
                } else {
                    return $this->failServerError("No se pudo abrir el archivo ZIP");
                }

                // Buscar el archivo SQL dentro del ZIP
                $sqlFiles = glob($extractPath . '/*.sql');
                if (empty($sqlFiles)) {
                    return $this->failServerError("No se encontró ningún archivo .sql dentro del ZIP");
                }

                $sqlFile = $sqlFiles[0];

                // Restaurar con PostgreSQLBackup
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
                $pdo = new \PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $pgsqlBackup = new \PostgresqlBackupManager\PostgreSQLBackup(
                        $pdo,
                        $dbname,
                        $username,
                        $password,
                        $host,
                        (int) $port,
                        ROOTPATH . "writable/database/backup"
                );

                $pgsqlBackup->restore($sqlFile);

                // Limpieza
                unlink($sqlFile);
                rmdir($extractPath);
            } else {
                // Si es MySQL/MariaDB, usar directamente el archivo SQL
                $dsn = "mysql:host=$host;port=$port;dbname=$dbname";
                $pdo = new \PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $mysqlBackup = new MySQLBackup($pdo);
                $mysqlBackup->restore($backupPath, true);
            }

            // Restaurar metadatos
            $this->backups->where("id >", 0)->delete();
            $this->backups->purgeDeleted();
            $this->backups->insertBatch($allBackups);

            return $this->respondCreated(true, lang("backups.msg.restored"));
        } catch (Exception $ex) {
            return $this->failServerError("Error al restaurar: " . $ex->getMessage());
        }
    }

    /**
     * Download Backup
     */
    public function downloadBackup($uuid) {
        helper('auth');
        $userName = user()->username;
        $idUser = user()->id;

        $authorize = service('authorization');

        if (!($authorize->hasPermission("backups-permissions", $idUser))) {
            return;
        }

        $dataBackup = $this->backups->select("SQLFile,created_at")->where("uuid", $uuid)->first();

        if (!$dataBackup || !file_exists($dataBackup["SQLFile"])) {
            return $this->failNotFound("Archivo de respaldo no encontrado");
        }

        $filePath = $dataBackup["SQLFile"];
        $fileName = "backup_" . date("Ymd_His", strtotime($dataBackup["created_at"]));

        if (str_ends_with($filePath, '.zip')) {
            $this->response->setHeader("Content-Type", "application/zip");
            $this->response->setHeader("Content-Disposition", "attachment; filename={$fileName}.zip");
        } else {
            $this->response->setHeader("Content-Type", "application/sql");
            $this->response->setHeader("Content-Disposition", "attachment; filename={$fileName}.sql");
        }

        // Leer y enviar contenido
        $this->response->setBody(file_get_contents($filePath));
        return $this->response;
    }
}
