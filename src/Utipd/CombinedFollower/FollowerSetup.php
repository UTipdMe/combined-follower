<?php 

namespace Utipd\CombinedFollower;

use PDO;

/**
*       
*/
class FollowerSetup
{

    protected $db_connection = null;
    
    function __construct(PDO $db_connection, $db_name) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }


    public function processAnyNewBlocks() {
        $this->getLastBlock();
    }


    public function initializeAndEraseDatabase() {
        $this->eraseDatabase();
        $this->InitializeDatabase();
    }

    public function eraseDatabase() {

        ////////////////////////////////////////////////////////////////////////
        // db

        $this->exec("CREATE DATABASE IF NOT EXISTS `{$this->db_name}`;");
        $this->exec("use `{$this->db_name}`;");

        ////////////////////////////////////////////////////////////////////////
        // drop database

        $this->exec("DROP TABLE IF EXISTS `watchaddress`;");
        $this->exec("DROP TABLE IF EXISTS `blockchaintransaction`;");
        $this->exec("DROP TABLE IF EXISTS `callbacktriggered`;");
        $this->exec("DROP TABLE IF EXISTS `pendingcarriertx`;");

    } 

    public function InitializeDatabase() {

        ////////////////////////////////////////////////////////////////////////
        // db

        $this->exec("CREATE DATABASE IF NOT EXISTS `{$this->db_name}`;");
        $this->exec("use `{$this->db_name}`;");


        ////////////////////////////////////////////////////////////////////////
        // tabls
        
        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `blockchaintransaction` (
    `id`            int(11) unsigned NOT NULL AUTO_INCREMENT,
    `blockId`       int(20) unsigned NOT NULL DEFAULT 0,
    `destination`   varbinary(34) NOT NULL,
    `tx_hash`       varbinary(64) NOT NULL DEFAULT '',
    `isMempool`     int(1) NOT NULL DEFAULT '0',
    `isNative`      int(1) NOT NULL DEFAULT '0',
    `document`      LONGTEXT NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `blockId` (`blockId`),
    KEY `destination` (`destination`),
    KEY `isMempool_isNative` (`isMempool`,`isNative`),
    UNIQUE KEY `tx_hash_destination_isNative` (`tx_hash`,`destination`,`isNative`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8;
EOT
        );

        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `watchaddress` (
    `address` varbinary(34) NOT NULL,
    PRIMARY KEY (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOT
        );

        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `callbacktriggered` (
    `tx_hash`       varbinary(64) NOT NULL DEFAULT '',
    `destination`   varbinary(34) NOT NULL,
    `confirmations` int(11) NOT NULL DEFAULT '0',
    `blockId`       int(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`tx_hash`,`destination`,`confirmations`),
    KEY `blockId` (`blockId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOT
        );

        $this->exec($_t = <<<EOT
CREATE TABLE IF NOT EXISTS `pendingcarriertx` (
    `tx_hash`       varbinary(64) NOT NULL DEFAULT '',
    `isMempool`     int(1) NOT NULL DEFAULT '0',
    `blockId`       int(20) unsigned NOT NULL DEFAULT 0,
    `timestamp`     int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`tx_hash`),
    KEY `blockId` (`blockId`),
    KEY `timestamp` (`timestamp`),
    KEY `isMempool` (`isMempool`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOT
        );

    }

    protected function exec($sql) {
        $result = $this->db_connection->exec($sql);
    }

}
