<?php

namespace Utipd\CombinedFollower\Models\Directory;

use Utipd\MysqlModel\BaseDocumentMysqlDirectory;
use Exception;

/*
* BlockchainTransactionDirectory
*/
class BlockchainTransactionDirectory extends BaseDocumentMysqlDirectory
{

    protected $column_names = ['blockId','tx_hash','destination','isMempool','isNative',];



}
