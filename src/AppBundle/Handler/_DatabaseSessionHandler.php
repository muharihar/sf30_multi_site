<?php

namespace AppBundle\Handler;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\RequestStack;


class DatabaseSessionHandler extends \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler {
    
    /**
     * @var \PDO|null PDO instance or null when not connected yet
     */
    private $_pdo;
    
    /**
     *
     * @var string Database driver
     */
    private $_driver = 'mysql';
    
    /**
     * @var string Table name
     */
    private $_table = 'sessions';

    /**
     * @var string Column for session id
     */
    private $_idCol = 'sess_id';

    /**
     * @var string Column for session data
     */
    private $_dataCol = 'sess_data';

    /**
     * @var string Column for lifetime
     */
    private $_lifetimeCol = 'sess_lifetime';

    /**
     * @var string Column for timestamp
     */
    private $_timeCol = 'sess_time';
    
    /**
     *
     * @var string Column for Client IP Address
     */
    private $ipAddressCol = 'sess_client_ip_address';
    
    /**
     * @var string Username when lazy-connect
     */
    private $_username = '';

    /**
     * @var string Password when lazy-connect
     */
    private $_password = '';

    /**
     * @var array Connection options when lazy-connect
     */
    private $_connectionOptions = array();

    /**
     * @var int The strategy for locking, see constants
     */
    private $_lockMode = self::LOCK_TRANSACTIONAL;
    
    public function __construct($pdoOrDsn = null, array $options = array())
    {
        parent::__construct($pdoOrDsn,$options);
        
        if ($pdoOrDsn instanceof \PDO) {
            if (\PDO::ERRMODE_EXCEPTION !== $pdoOrDsn->getAttribute(\PDO::ATTR_ERRMODE)) {
                throw new \InvalidArgumentException(sprintf('"%s" requires PDO error mode attribute be set to throw Exceptions (i.e. $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION))', __CLASS__));
            }

            $this->_pdo = $pdoOrDsn;
            $this->_driver = $this->_pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } else {
            $this->_dsn = $pdoOrDsn;
        }
        
        $this->_table = isset($options['db_table']) ? $options['db_table'] : $this->_table;
        $this->_idCol = isset($options['db_id_col']) ? $options['db_id_col'] : $this->_idCol;
        $this->_dataCol = isset($options['db_data_col']) ? $options['db_data_col'] : $this->_dataCol;
        $this->_lifetimeCol = isset($options['db_lifetime_col']) ? $options['db_lifetime_col'] : $this->_lifetimeCol;
        $this->_timeCol = isset($options['db_time_col']) ? $options['db_time_col'] : $this->_timeCol;
        $this->_username = isset($options['db_username']) ? $options['db_username'] : $this->_username;
        $this->_password = isset($options['db_password']) ? $options['db_password'] : $this->_password;
        $this->_connectionOptions = isset($options['db_connection_options']) ? $options['db_connection_options'] : $this->_connectionOptions;
        $this->_lockMode = isset($options['lock_mode']) ? $options['lock_mode'] : $this->_lockMode;
    }
    
    /**
     * {@inheritdoc}
     */
    public function open($savePath, $sessionName)
    {
        parent::open($savePath,$sessionName);
        
        if (null === $this->_pdo) {
            $this->_connect($this->_dsn ?: $savePath);
        }

        return true;
    }
    
    /**
     * Lazy-connects to the database.
     *
     * @param string $dsn DSN string
     */
    private function _connect($dsn)
    {
        $this->_pdo = new \PDO($dsn, $this->_username, $this->_password, $this->_connectionOptions);
        $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->_driver = $this->_pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
    
    /**
     * Returns a merge/upsert (i.e. insert or update) SQL query when supported by the database for writing session data.
     *
     * @return string|null The SQL string or null when not supported
     */
    private function _getMergeSql()
    {
        switch ($this->_driver) {
            case 'mysql':
                return "INSERT INTO $this->_table ($this->_idCol, $this->_dataCol, $this->_lifetimeCol, $this->_timeCol, $this->ipAddressCol) VALUES (:id, :data, :lifetime, :time, :ip_address) ".
                    "ON DUPLICATE KEY UPDATE $this->_dataCol = VALUES($this->_dataCol), $this->_lifetimeCol = VALUES($this->_lifetimeCol), $this->_timeCol = VALUES($this->_timeCol), $this->ipAddressCol = VALUES($this->ipAddressCol)";
            case 'oci':
                // DUAL is Oracle specific dummy table
                return "MERGE INTO $this->_table USING DUAL ON ($this->_idCol = :id) ".
                    "WHEN NOT MATCHED THEN INSERT ($this->_idCol, $this->_dataCol, $this->_lifetimeCol, $this->_timeCol) VALUES (:id, :data, :lifetime, :time) ".
                    "WHEN MATCHED THEN UPDATE SET $this->_dataCol = :data, $this->_lifetimeCol = :lifetime, $this->_timeCol = :time";
            case 'sqlsrv' === $this->driver && version_compare($this->_pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), '10', '>='):
                // MERGE is only available since SQL Server 2008 and must be terminated by semicolon
                // It also requires HOLDLOCK according to http://weblogs.sqlteam.com/dang/archive/2009/01/31/UPSERT-Race-Condition-With-MERGE.aspx
                return "MERGE INTO $this->_table WITH (HOLDLOCK) USING (SELECT 1 AS dummy) AS src ON ($this->_idCol = :id) ".
                    "WHEN NOT MATCHED THEN INSERT ($this->_idCol, $this->_dataCol, $this->_lifetimeCol, $this->_timeCol) VALUES (:id, :data, :lifetime, :time) ".
                    "WHEN MATCHED THEN UPDATE SET $this->_dataCol = :data, $this->_lifetimeCol = :lifetime, $this->_timeCol = :time;";
            case 'sqlite':
                return "INSERT OR REPLACE INTO $this->_table ($this->_idCol, $this->_dataCol, $this->_lifetimeCol, $this->_timeCol) VALUES (:id, :data, :lifetime, :time)";
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $data)
    {
        global $request;
        
        $maxlifetime = (int) ini_get('session.gc_maxlifetime');

        try {
            //$er = new RequestStack();
            //var_dump($er);
            
            $_client_ip_address = $request->getClientIp();
                    
            // We use a single MERGE SQL query when supported by the database.
            $mergeSql = $this->_getMergeSql();

            if (null !== $mergeSql) {
                $mergeStmt = $this->_pdo->prepare($mergeSql);
                $mergeStmt->bindParam(':id', $sessionId, \PDO::PARAM_STR);
                $mergeStmt->bindParam(':data', $data, \PDO::PARAM_LOB);
                $mergeStmt->bindParam(':lifetime', $maxlifetime, \PDO::PARAM_INT);
                $mergeStmt->bindValue(':time', time(), \PDO::PARAM_INT);
                $mergeStmt->bindValue(':ip_address', $_client_ip_address, \PDO::PARAM_STR);
                $mergeStmt->execute();

                return true;
            }

            $updateStmt = $this->_pdo->prepare(
                "UPDATE $this->_table SET $this->_dataCol = :data, $this->_lifetimeCol = :lifetime, $this->_timeCol = :time WHERE $this->_idCol = :id, $this->ipAddressCol = :ip_address"
            );
            $updateStmt->bindParam(':id', $sessionId, \PDO::PARAM_STR);
            $updateStmt->bindParam(':data', $data, \PDO::PARAM_LOB);
            $updateStmt->bindParam(':lifetime', $maxlifetime, \PDO::PARAM_INT);
            $updateStmt->bindValue(':time', time(), \PDO::PARAM_INT);
            $updateStmt->bindValue(':ip_address', $_client_ip_address, \PDO::PARAM_STR);
            $updateStmt->execute();

            // When MERGE is not supported, like in Postgres, we have to use this approach that can result in
            // duplicate key errors when the same session is written simultaneously (given the LOCK_NONE behavior).
            // We can just catch such an error and re-execute the update. This is similar to a serializable
            // transaction with retry logic on serialization failures but without the overhead and without possible
            // false positives due to longer gap locking.
            if (!$updateStmt->rowCount()) {
                try {
                    $insertStmt = $this->_pdo->prepare(
                        "INSERT INTO $this->_table ($this->_idCol, $this->_dataCol, $this->_lifetimeCol, $this->_timeCol, $this->ipAddressCol) VALUES (:id, :data, :lifetime, :time, :ip_address)"
                    );
                    $insertStmt->bindParam(':id', $sessionId, \PDO::PARAM_STR);
                    $insertStmt->bindParam(':data', $data, \PDO::PARAM_LOB);
                    $insertStmt->bindParam(':lifetime', $maxlifetime, \PDO::PARAM_INT);
                    $insertStmt->bindValue(':time', time(), \PDO::PARAM_INT);
                    $insertStmt->bindValue(':ip_address', $_client_ip_address, \PDO::PARAM_STR);
                    $insertStmt->execute();
                } catch (\PDOException $e) {
                    // Handle integrity violation SQLSTATE 23000 (or a subclass like 23505 in Postgres) for duplicate keys
                    if (0 === strpos($e->getCode(), '23')) {
                        $updateStmt->execute();
                    } else {
                        throw $e;
                    }
                }
            }
        } catch (\PDOException $e) {
            $this->rollback();

            throw $e;
        }

        return true;
    }
    
}