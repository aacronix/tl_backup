<?php
ini_set("memory_limit", "1000M");

$dbLogin = "";
$dbHost = "";
$dbPassword = "";
$dbName = "";

/*   CommonFunction start   */
function GetServerInformation()
{
    $arrResult = array();
    $arrResult["phpVersion"] = phpversion();
    $arrResult["shellCommand"] = false;
    if (IsShellCommands())
        $arrResult["shellCommand"] = true;

    $arrResult["thisDirectory"] = GetThisPath();
    $arrResult["backupFilePath"] = $arrResult["thisDirectory"] . "/" . GetBackupFileName();
    $arrResult["backupFilePathPerms"] = substr(sprintf('%o', fileperms("/")), -4);


    return $arrResult;
}

function GetBackupFileName()
{
    $domen = $_SERVER['SERVER_NAME'];
    $backupName = $domen . '_backup_' . date("Y-m-d");
    return $backupName;
}

function GetThisPath()
{
    return getcwd();
}

/*   CommonFunction end   */

/*   BackupShell start   */
function IsShellCommands()
{
    $output = shell_exec('ls -lart');
    if (!empty($output))
        return true;
    return false;
}

/*
 * POST Params: url - site
 * */
function detectCMS()
{
    $toolUrl = "http://onlinewebtool.com/class/cmsdetector.php";

}

function hashDirectory($directory)
{
    if (!is_dir($directory)) {
        return false;
    }

    $files = array();
    $dir = dir($directory);

    while (false !== ($file = $dir->read())) {
        if ($file != '.' and $file != '..') {
            if (is_dir($directory . '/' . $file)) {
                $files[] = hashDirectory($directory . '/' . $file);
            } else {
                $files[] = md5_file($directory . '/' . $file);
            }
        }
    }

    $dir->close();

    return md5(implode('', $files));
}

function BackupFilesShell($backupFilePath, $directory)
{
    $dir = trim($directory);
    $fullFileName = $backupFilePath . '.tar.gz';
    shell_exec("tar -cvf " . $fullFileName . " " . $directory . "/* ");
    return $fullFileName;
}

function BackupDBShell($backupFilePath, $db_host, $db_user, $db_password, $db_name)
{
    $fullFileName = $backupFilePath . '.sql';
    $command = 'mysqldump -h' . $db_host . ' -u' . $db_user . ' -p' . $db_password . ' ' . $db_name . ' > ' . $fullFileName;
    shell_exec($command);
    return $fullFileName;
}

/*   BackupShell end   */

/*   BackupPHP start   */

function BackupFilesPHP($destination, $source)
{
    var_dump(hashDirectory($source));
    if (!extension_loaded('zip') || !file_exists($source)) {
        return false;
    }

    $zip = new ZipArchive();
    if (!$zip->open($destination . '.zip', ZIPARCHIVE::CREATE)) {
        return false;
    }

    $source = str_replace('\\', '/', realpath($source));

    if (is_dir($source) === true) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file) {
            $file = str_replace('\\', '/', $file);

            // Ignore "." and ".." folders
            if (in_array(substr($file, strrpos($file, '/') + 1), array('.', '..')))
                continue;

            $file = realpath($file);

            if (is_dir($file) === true) {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            } else if (is_file($file) === true) {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
    } else if (is_file($source) === true) {
        $zip->addFromString(basename($source), file_get_contents($source));
    }

    return $zip->close();
}

function BackupDatabaseTables($backupFilePath, $host, $user, $pass, $name, $tables = '*')
{
    $backupFile = $backupFilePath . '.sql';

    $mtables = array();
    $contents = "-- Database: `" . $name . "` --\n";

    $mysqli = new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_error) {
        die('Error : (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
    }

    $results = $mysqli->query("SHOW TABLES");

    while ($row = $results->fetch_array()) {
        $mtables[] = $row[0];
    }

    foreach ($mtables as $table) {
        $contents .= "-- Table `" . $table . "` --\n";

        $results = $mysqli->query("SHOW CREATE TABLE " . $table);
        while ($row = $results->fetch_array()) {
            $contents .= $row[1] . ";\n\n";
        }

        $results = $mysqli->query("SELECT * FROM " . $table);
        $row_count = $results->num_rows;
        $fields = $results->fetch_fields();
        $fields_count = count($fields);

        $insert_head = "INSERT INTO `" . $table . "` (";
        for ($i = 0; $i < $fields_count; $i++) {
            $insert_head .= "`" . $fields[$i]->name . "`";
            if ($i < $fields_count - 1) {
                $insert_head .= ', ';
            }
        }
        $insert_head .= ")";
        $insert_head .= " VALUES\n";

        if ($row_count > 0) {
            $r = 0;
            while ($row = $results->fetch_array()) {
                if (($r % 400) == 0) {
                    $contents .= $insert_head;
                }
                $contents .= "(";
                for ($i = 0; $i < $fields_count; $i++) {
                    $row_content = str_replace("\n", "\\n", $mysqli->real_escape_string($row[$i]));

                    switch ($fields[$i]->type) {
                        case 8:
                        case 3:
                            $contents .= $row_content;
                            break;
                        default:
                            $contents .= "'" . $row_content . "'";
                    }
                    if ($i < $fields_count - 1) {
                        $contents .= ', ';
                    }
                }
                if (($r + 1) == $row_count || ($r % 400) == 399) {
                    $contents .= ");\n\n";
                } else {
                    $contents .= "),\n";
                }
                $r++;
            }
        }
    }

    $fp = fopen($backupFile, 'w+');
    if (($result = fwrite($fp, $contents))) {
        echo "Backup file created '--$backupFile' ($result)";
    }
    fclose($fp);
}

/*   BackupPHP end   */

function GetRightTableName($sourceName){
    $pattern = '/[A-Za-z][A-Za-z0-9_]*/';
    preg_match($pattern, $sourceName, $matches);

    return $matches ? $matches[0] : false;
}

abstract class ICMS
{
    abstract public function GetData();
}

class BitrixCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/dbconn.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $DBLogin;
            $dbHost = $DBHost;
            $dbPassword = $DBPassword;
            $dbName = $DBName;
        } else {
            echo 'oh sorry';
        }
    }
}

class WordPressCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = DB_USER;
            $dbHost = DB_HOST;
            $dbPassword = DB_PASSWORD;
            $dbName = DB_NAME;
        } else {
            echo 'oh sorry';
        }
    }
}

class JoomlaCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/configuration.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $jconfig = (array)(new JConfig);
            $dbLogin = $jconfig['user'];
            $dbHost = $jconfig['host'];
            $dbPassword = $jconfig['password'];
            $dbName = $jconfig['db'];
        } else {
            echo 'oh sorry';
        }
    }
}

//TODO: возможно 4 и 5 и 6 drupal можно объединить
class Drupal4CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/sites/default/settings.php';
        if ($dbconn = file_exists($file)) {
            unset($_SERVER['HTTP_HOST']);
            @include($file);
            echo 'the file is mine';
            $db = parse_url($db_url);
            $dbLogin = $db['user'];
            $dbHost = $db['host'];
            $dbPassword = $db['pass'];
            $dbName = GetRightTableName($db['path']);
        } else {
            echo 'oh sorry';
        }
    }
}

class Drupal5CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/sites/default/settings.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $db = parse_url($db_url);
            $dbLogin = $db['user'];
            $dbHost = $db['host'];
            $dbPassword = $db['pass'];
            $dbName = GetRightTableName($db['path']);
        } else {
            echo 'oh sorry';
        }
    }
}

//TODO: возможно надо проверить на несколько основных названий конфигурации с настройками (settings.php, default.settings.php и т.д.)
class Drupal6CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/sites/default/default.settings.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $db = parse_url($db_url);
            $dbLogin = $db['user'];
            $dbHost = $db['host'];
            $dbPassword = $db['pass'];
            $dbName = GetRightTableName($db['path']);
        } else {
            echo 'oh sorry';
        }
    }
}

class Drupal7CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/sites/default/settings.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $databases['default']['default']['username'];
            $dbHost = $databases['default']['default']['host'];
            $dbPassword = $databases['default']['default']['password'];
            $dbName = $databases['default']['default']['database'];
        } else {
            echo 'oh sorry';
        }
    }
}

class Drupal8CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/sites/default/settings.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $databases['default']['default']['username'];
            $dbHost = $databases['default']['default']['host'];
            $dbPassword = $databases['default']['default']['password'];
            $dbName = $databases['default']['default']['database'];
        } else {
            echo 'oh sorry';
        }
    }
}


class MadeSimpleCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $config['db_username'];
            $dbHost = $config['db_hostname'];
            $dbPassword = $config['db_password'];
            $dbName = $config['db_name'];
        } else {
            echo 'oh sorry';
        }
    }
}


class ModxRevolutionCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/core/config/config.inc.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $database_user;
            $dbHost = $database_server;
            $dbPassword = $database_password;
            $dbName = $dbase;
        } else {
            echo 'oh sorry';
        }
    }
}

// TODO:Проверка на лишние знаки в названии таблицы
class ModxEvolutionCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/manager/includes/config.inc.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $database_user;
            $dbHost = $database_server;
            $dbPassword = $database_password;
            $dbName = $dbase;
        } else {
            echo 'oh sorry';
        }
    }
}

class Typo3Ver6CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/typo3conf/LocalConfiguration.php';
        if ($dbconn = file_exists($file)) {
            $data = @include($file);
            echo 'the file is mine';
            $dbLogin = $data['DB']['username'];
            $dbHost = $data['DB']['host'];
            $dbPassword = $data['DB']['password'];
            $dbName = $data['DB']['database'];
        } else {
            echo 'oh sorry';
        }
    }
}

class Typo3Ver7CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/typo3conf/LocalConfiguration.php';
        if ($dbconn = file_exists($file)) {
            $data = @include($file);
            echo 'the file is mine';
            $dbLogin = $data['DB']['username'];
            $dbHost = $data['DB']['host'];
            $dbPassword = $data['DB']['password'];
            $dbName = $data['DB']['database'];
        } else {
            echo 'oh sorry';
        }
    }
}

class Typo3Ver8CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/manager/includes/config.inc.php';
        if ($dbconn = file_exists($file)) {
            @include($file);
            echo 'the file is mine';
            $dbLogin = $database_user;
            $dbHost = $database_server;
            $dbPassword = $database_password;
            $dbName = $dbase;
        } else {
            echo 'oh sorry';
        }
    }
}

class UmiCMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/config.ini';
        if ($dbconn = file_exists($file)) {
            $ini_array = parse_ini_file($file, true);
            echo 'the file is mine';
            $dbLogin = $ini_array['connections']['core.login'];
            $dbHost = $ini_array['connections']['core.host'];
            $dbPassword = $ini_array['connections']['core.password'];
            $dbName = $ini_array['connections']['core.dbname'];
        } else {
            echo 'oh sorry';
        }
    }
}


class HostCms6CMS extends ICMS
{
    public function GetData()
    {
        global $dbLogin, $dbHost, $dbPassword, $dbName;

        $file = $_SERVER['DOCUMENT_ROOT'] . '/modules/core/config/database.php';
        if ($dbconn = file_exists($file)) {
            $data = @include($file);
            echo 'the file is mine';
            $dbLogin = $data['default']['username'];
            $dbHost = $data['default']['host'];
            $dbPassword = $data['default']['password'];
            $dbName = $data['default']['database'];
        } else {
            echo 'oh sorry';
        }
    }
}

class UndefinedCMS extends ICMS
{
    public function GetData()
    {

    }
}

abstract class CMSFactoryAbstract
{
    public function Create($type)
    {
        switch ($type) {
            case "bitrix":
                return new BitrixCMS();
            case "wp":
                return new WordPressCMS();
            case "joomla":
                return new JoomlaCMS();
            case "drupal4":
                return new Drupal4CMS();
            case "drupal5":
                return new Drupal5CMS();
            case "drupal6":
                return new Drupal6CMS();
            case "drupal7":
                return new Drupal7CMS();
            case "drupal8":
                return new Drupal8CMS();
            case "ms":
                return new MadeSimpleCMS();
            case "modxr":
                return new ModxRevolutionCMS();
            case "modxe":
                return new ModxEvolutionCMS();
            case "typo36":
                return new Typo3Ver6CMS();
            case "typo37":
                return new Typo3Ver7CMS();
            case "typo38":
                return new Typo3Ver8CMS();
            case "umi":
                return new UmiCMS();
            case "hostcms6":
                return new HostCms6CMS();
            default:
                return new UndefinedCMS();
        }
    }
}

class CMSFactory extends CMSFactoryAbstract
{

}


set_time_limit(0); // Убираем ограничение на максимальное время работы скрипта
$arrInformation = GetServerInformation();

if (!empty($_POST["backupAction"])):
    $backupName = GetBackupFileName();
    $action = $_POST["backupAction"];
    $download = array();
    $BackupFileName = "/" . GetBackupFileName();

    switch ($action) {
        case "backupFilePHP":
            BackupFilesPHP($arrInformation["backupFilePath"], $arrInformation["thisDirectory"] . $_POST["directory"]);
            $download["files"] = $BackupFileName . '.zip';
            echo(md5_file($BackupFileName . '.zip'));
            break;
        case "backupFileShell":
            BackupFilesShell($arrInformation["backupFilePath"], $arrInformation["thisDirectory"] . $_POST["directory"]);
            $download["files"] = $BackupFileName . '.tar.gz';
            break;
        case "backupDatabasePHP":
            BackupDatabaseTables($arrInformation["backupFilePath"], $_POST["host"], $_POST["user"], $_POST["password"], $_POST["database"]);
            $download["sql"] = $BackupFileName . '.sql';
            break;
        case "backupDatabaseShell":
            BackupDBShell($arrInformation["backupFilePath"], $_POST["host"], $_POST["user"], $_POST["password"], $_POST["database"]);
            $download["sql"] = $BackupFileName . '.sql';
            break;
        default:
            break;
    }
endif;

if (!empty($_POST["cmsSelectAction"])):
    $selectedCMS = $_POST["cmsSelector"];

    $cmsFactory = new CMSFactory();
    $cms = $cmsFactory->Create($selectedCMS);
    $cms->GetData();
endif;
?>

<!DOCTYPE html>
<html>
<head lang="ru">
    <meta charset="UTF-8">
    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css">
    <title>Создание резервной копии сайта</title>
</head>
<body>
<style>
    .b-contener {
        max-width: 800px;
        width: 100%;
        margin: 0 auto;
    }

    .download-bt {
        text-align: center;
        width: 300px;
        margin: 25px auto;
        display: block;
    }
</style>

<div class="b-contener">
    <h1 class="text-center">Создание резервной копии сайта</h1>

    <blockquote>
        <p>Версия PHP: <?= $arrInformation["phpVersion"]; ?></p>
        <p>Поддержка shell: <? if ($arrInformation["shellCommand"]) {
                echo "YES";
            } else {
                echo "NO";
            } ?></p>
        <p>Текущий каталог: <?= $arrInformation["thisDirectory"]; ?></p>
        <p>Права на текущий каталог: <?= $arrInformation["backupFilePathPerms"]; ?></p>
        <p>Бекап: <?= $arrInformation["backupFilePath"]; ?>(.tar.gz .sql .zip)</p>
        <footer>Общая информация</footer>
    </blockquote>

    <h3 class="text-center">Создание резервной копии файлов</h3>

    <form role="form" action="" method="post">
        <div class="form-group">
            <label for="InputPath">Путь к папке относительно корня</label>
            <input type="text" name="directory" class="form-control" id="InputPath" required
                   placeholder="Путь относительно корня">
        </div>
        <button type="submit" name="backupAction" value="backupFilePHP" class="btn btn-default">Создать бекап через
            php
        </button>
        <button type="submit" name="backupAction" value="backupFileShell"
                class="btn btn-default" <? if (!$arrInformation["shellCommand"]) echo "disabled" ?> >Создать бекап через
            shell
        </button>
    </form>

    <? if (!empty($download["files"])): ?>
        <a href="<?= $download["files"] ?>" title="" download class="btn btn-default btn-lg btn-success download-bt">Скачать
            архив</a>
    <? endif; ?>


    <h3 class="text-center">Создание резервной копии базы данных</h3>

    <form role="form" action="" method="post">
        <div class="form-group">
            <label for="InputPath">Какая CMS?</label>
            <select name="cmsSelector" id="cms-selector" class="form-control" required>
                <option value="undefined">Я не знаю</option>
                <option value="bitrix">Bitrix</option>
                <option value="wp">WordPress</option>
                <option value="joomla">Joomla</option>
                <option value="drupal4">Drupal 4</option>
                <option value="drupal5">Drupal 5</option>
                <option value="drupal6">Drupal 6</option>
                <option value="drupal7">Drupal 7</option>
                <option value="drupal8">Drupal 8</option>
                <option value="ms">MadeSimple</option>
                <option value="modxe">MODx Evolution</option>
                <option value="modxr">MODx Revolution</option>
                <option value="typo36">TYPO3 ver.6</option>
                <option value="typo37">TYPO3 ver.7</option>
                <option value="typo38">TYPO3 ver.8, всё так же как и в 7, но это не точно (пока не добавлено)</option>
                <option value="umi">UMI.CMS</option>
                <option value="hostcms5">HostCMS5 (пока не добавлено)</option>
                <option value="hostcms6">HostCMS6</option>
                <option value="netcat">Netcat (пока не добавлено)</option>
                <option value="ural">Ural CMS (пока не добавлено)</option>
            </select>
            <button type="submit" name="cmsSelectAction" value="selectEvent" class="btn btn-default btn-lg btn-success download-bt">
                Попробовать вытянуть доступы
            </button>
        </div>
    </form>
    <form role="form" action="" method="post">
        <div class="form-group">
            <label for="InputHost">Host</label>
            <input type="text" name="host" class="form-control" id="InputHost" required placeholder="localhost"
                   value="<?= $dbHost ?>">
        </div>
        <div class="form-group">
            <label for="InputUser">User</label>
            <input type="text" name="user" class="form-control" id="InputUser" required placeholder="username"
                   value="<?= $dbLogin ?>">
        </div>
        <div class="form-group">
            <label for="InputPassword">Password</label>
            <input type="text" name="password" class="form-control" id="InputPassword" placeholder="password" required
                   value="<?= $dbPassword ?>">
        </div>
        <div class="form-group">
            <label for="InputDatabase">Database</label>
            <input type="text" name="database" class="form-control" id="InputDatabase" required placeholder="database"
                   value="<?= $dbName ?>">
        </div>
        <button type="submit" name="backupAction" value="backupDatabasePHP" class="btn btn-default">Создать
            бекап через php
        </button>
        <button type="submit" name="backupAction" value="backupDatabaseShell"
                class="btn btn-default" <? if (!$arrInformation["shellCommand"]) echo "disabled" ?>>Создать бекап через
            shell
        </button>
    </form>

    <? if (!empty($download["sql"])): ?>
        <a href="<?= $download["sql"] ?>" title="" download class="btn btn-default btn-lg btn-success download-bt">Скачать
            архив</a>
    <? endif; ?>

</div>
</body>
</html>