<?php

use Utipd\CombinedFollower\Follower;
use Utipd\CombinedFollower\FollowerSetup;
use Utipd\MysqlModel\ConnectionManager;
use \Exception;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class FollowerBlocksTest extends \PHPUnit_Framework_TestCase
{


    public function testProcessBlocks() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();

        // watch blocks found
        $follower = $this->getFollower();
        $found_native_block_ids_map = [];
        $found_xpc_block_ids_map = [];
        $follower->handleNewNativeBlock(function($block_id) use (&$found_native_block_ids_map) {
            $found_native_block_ids_map[$block_id] = true;
        });
        $follower->handleNewCounterpartyBlock(function($block_id) use (&$found_xpc_block_ids_map) {
            $found_xpc_block_ids_map[$block_id] = true;
        });

        // run 2 iterations
        $follower->setGenesisBlock(300000);
        $follower->runOneIteration();
        $follower->runOneIteration();

        // check blocks found
        PHPUnit::assertArrayHasKey(300000, $found_native_block_ids_map);
        PHPUnit::assertArrayHasKey(300001, $found_native_block_ids_map);
        PHPUnit::assertArrayHasKey(300000, $found_xpc_block_ids_map);
        PHPUnit::assertArrayHasKey(300001, $found_xpc_block_ids_map);
    }


    public function testReceiveMempoolTransactions() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();

        // watch mempool tx
        $follower = $this->getFollower();
        // $follower->addAddressToWatch('dest02');
        $native_txs_map = [];
        $xcp_txs_map = [];
        $follower->handleMempoolTransaction(function ($transaction, $current_block_id) use (&$native_txs_map, &$xcp_txs_map) {
            if (!!$transaction['isNative']) {
                $native_txs_map[$current_block_id][] = $transaction;
            } else {
                $xcp_txs_map[$current_block_id][] = $transaction;
            }
        });

        // run 2 iterations
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();

        // check transactions found (each one is only triggered once)
        PHPUnit::assertCount(1, $xcp_txs_map);
        PHPUnit::assertCount(1, $native_txs_map);

        PHPUnit::assertEquals('13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn', $xcp_txs_map['300000'][0]['source']);
        PHPUnit::assertEquals(0.5*100000000, $native_txs_map['300000'][0]['quantity']);
    }


    // when one transaction involves 2 different watch addresses,
    //   we need to make sure that after the first watch address deals with it, the second also sees it.
    public function testTwoWatchAddressMempoolTransactions() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();
        // this has a xcp payment to dest02
        // we need to have a btc payment to otherwatch01 as well
        $this->mempool_native_transactions['rawtxid001']->vout[0]->scriptPubKey->addresses[0] = 'otherwatch01';
        $this->mempool_native_transactions['rawtxid001']->txid = 'mempool01txhash';
        // echo "\$this->mempool_xcp_transactions:\n".json_encode($this->mempool_xcp_transactions, 192)."\n"; exit();


        // watch mempool tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('otherwatch01');


        // send a counterparty asset with change from a to b, but watch both a and b
        $native_txs_map = [];
        $xcp_txs_map = [];
        $destination_addresses_map = ['native'=>[], 'counterparty'=>[]];
        $follower->handleMempoolTransaction(function ($transaction, $current_block_id) use (&$native_txs_map, &$xcp_txs_map, &$destination_addresses_map) {
            if (!!$transaction['isNative']) {
                $destination_addresses_map['native'][$transaction['destination']] = true;
                $native_txs_map[$current_block_id][] = $transaction;
            } else {
                $destination_addresses_map['counterparty'][$transaction['destination']] = true;
                $xcp_txs_map[$current_block_id][] = $transaction;
            }
        });

        // run 2 iterations
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();

        // check that both addresses were triggered, even though it was the same transaction
        PHPUnit::assertArrayHasKey('dest01', $destination_addresses_map['native']);
        PHPUnit::assertArrayHasKey('otherwatch01', $destination_addresses_map['native']);
        PHPUnit::assertArrayHasKey('dest01', $destination_addresses_map['counterparty']);
    }




    public function testReceiveConfirmedTransactions() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest02');
        $follower->setMaxConfirmationsForConfirmedCallback(3);
        $native_txs = [];
        $xcp_txs = [];
        $follower->handleConfirmedTransaction(function ($transaction, $confirmations, $current_block_id) use (&$native_txs, &$xcp_txs) {
            if (!!$transaction['isNative']) {
                $native_txs[] = ['confirmations' => $confirmations, 'blockId' => $current_block_id, 'tx' => $transaction];
            } else {
                $xcp_txs[] = ['confirmations' => $confirmations, 'blockId' => $current_block_id, 'tx' => $transaction];
            }
        });

        // run 6 iterations
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();
        $this->setCurrentBlock(300002);
        $follower->runOneIteration();
        $this->setCurrentBlock(300003);
        $follower->runOneIteration();
        $this->setCurrentBlock(300004);
        $follower->runOneIteration();
        $this->setCurrentBlock(300005);
        $follower->runOneIteration();

        // check blocks found
        PHPUnit::assertCount(6, $xcp_txs);

        // tx 0 - 1 confirmations
        PHPUnit::assertEquals(1, $xcp_txs[0]['confirmations']);
        PHPUnit::assertEquals('hash1', $xcp_txs[0]['tx']['tx_hash']);

        // tx 1 - 2 confirmations
        PHPUnit::assertEquals(2, $xcp_txs[1]['confirmations']);
        PHPUnit::assertEquals('hash1', $xcp_txs[1]['tx']['tx_hash']);

        // tx 2 - 1 confirmations
        PHPUnit::assertEquals(1, $xcp_txs[2]['confirmations']);
        PHPUnit::assertEquals('hash2', $xcp_txs[2]['tx']['tx_hash']);

        // tx 3 - 3 confirmations
        PHPUnit::assertEquals(3, $xcp_txs[3]['confirmations']);
        PHPUnit::assertEquals('hash1', $xcp_txs[3]['tx']['tx_hash']);

        // tx 4 - 2 confirmations
        PHPUnit::assertEquals(2, $xcp_txs[4]['confirmations']);
        PHPUnit::assertEquals('hash2', $xcp_txs[4]['tx']['tx_hash']);

        // tx 5 - 3 confirmations
        PHPUnit::assertEquals(3, $xcp_txs[5]['confirmations']);
        PHPUnit::assertEquals('hash2', $xcp_txs[5]['tx']['tx_hash']);
    }



    public function testOrphanedBlocksReprocessCounterpartyTransactions() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->setMaxConfirmationsForConfirmedCallback(3);

        // run 2 blocks
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();

        // validate next block is 300002
        PHPUnit::assertEquals(300001, $this->getNativeFollower()->getLastProcessedBlock());
        PHPUnit::assertEquals(300001, $this->getXCPDFollower()->getLastProcessedBlock());

        // now orphan next block 300001
        $this->native_blocks = $this->getSampleNativeBlocksForReorganizedChain();
        $this->setCurrentBlock(300003);
        $follower->runOneIteration();

        // validate next block is 300002 again
        PHPUnit::assertEquals(300001, $this->getNativeFollower()->getLastProcessedBlock());
        PHPUnit::assertEquals(300001, $this->getXCPDFollower()->getLastProcessedBlock());
    }



    public function testHandleOrphanedTransaction() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample blocks
        $this->initAllSampleData();

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest02');
        $follower->addAddressToWatch('1AEwxRGP4HdwrwoXo1rEKc3jihFmZUybCw');

        // handle orphaned transactions
        $orphaned_transactions = [];
        $follower->handleOrphanedTransaction(function($orphaned_transaction) use (&$orphaned_transactions) {
            $orphaned_transactions[] = $orphaned_transaction;
        });

        // run 2 blocks
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();

        // now orphan next block 300001
        $this->native_blocks = $this->getSampleNativeBlocksForReorganizedChain();
        $this->setCurrentBlock(300003);
        $follower->runOneIteration();

        // validate orphaned_transactions
        PHPUnit::assertCount(2, $orphaned_transactions);
        PHPUnit::assertEquals('1AEwxRGP4HdwrwoXo1rEKc3jihFmZUybCw', $orphaned_transactions[0]['destination']);
        PHPUnit::assertEquals('dest02', $orphaned_transactions[1]['destination']);
    }


    public function testCombinedBTCAndCounterpartyMempoolTransaction() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample data (for combined test)
        $this->initAllSampleData();
        $this->mempool_xcp_transactions = $this->buildSampleMempoolXCPTransactionsForCombined();
        $this->mempool_native_transactions = array_merge($this->buildSampleMempoolBTCTransactionsForCombined(), $this->buildSamplePreviousNativeTransactions());
        $this->mempool_native_tx_ids = array_keys($this->buildSampleMempoolBTCTransactionsForCombined());

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest01');


        // track mempool transactions
        $mempool_txs = [];
        $follower->handleMempoolTransaction(function ($transaction, $current_block_id) use (&$mempool_txs) {
            $mempool_txs[] = $transaction;
        });

        // add a BTC dust mempool transaction
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();

        PHPUnit::assertCount(1, $mempool_txs);
    }

    public function testCombinedBTCAndCounterpartyConfirmedTransaction() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample data (for combined test)
        $this->initAllSampleData();
        $this->xcp_transactions = $this->buildSampleXCPTransactionsForCombined();
        $this->native_transactions = $this->buildSampleBTCTransactionsForCombined();

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest01');

        // track confirmed transactions
        $confirmed_transactions = [];
        $follower->handleConfirmedTransaction(function ($transaction, $confirmations, $current_block_id) use (&$confirmed_transactions) {
            $confirmed_transactions[] = $transaction;
        });

        // add a BTC dust confirmed transaction
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();

        PHPUnit::assertCount(1, $confirmed_transactions);

        // do a second confirmation for the confirmation
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();

        PHPUnit::assertCount(2, $confirmed_transactions);
    }



    public function testConfirmedTransactionsWipeOutPendingMempoolCarrierTransactions() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample data (for combined test)
        $this->initAllSampleData();
        $this->mempool_xcp_transactions = $this->buildSampleMempoolXCPTransactionsForCombined();
        $this->mempool_native_transactions = array_merge($this->buildSampleMempoolBTCTransactionsForCombined(), $this->buildSamplePreviousNativeTransactions());
        $this->mempool_native_tx_ids = array_keys($this->buildSampleMempoolBTCTransactionsForCombined());
        // $this->xcp_transactions = $this->buildSampleXCPTransactionsForCombined();
        // $this->native_transactions = $this->buildSampleBTCTransactionsForCombined();

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest01');

        // track mempool transactions
        $mempool_txs = [];
        $follower->handleMempoolTransaction(function ($transaction, $current_block_id) use (&$mempool_txs) {
            $mempool_txs[] = $transaction;
        });
        // track confirmed transactions
        $confirmed_transactions = [];
        $follower->handleConfirmedTransaction(function ($transaction, $confirmations, $current_block_id) use (&$confirmed_transactions) {
            $confirmed_transactions[] = $transaction;
        });

        // add a BTC dust confirmed transaction
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();

        PHPUnit::assertCount(1, $mempool_txs);
        PHPUnit::assertCount(1, $confirmed_transactions);

        // do a second confirmation for the confirmation
        $this->mempool_xcp_transactions = [];
        $this->mempool_native_transactions = [];
        $this->mempool_native_tx_ids = [];
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();

        // see if combined_follower_test.pendingcarriertx is erased
        $sth = $this->getPDO(getenv('DB_NAME'))->query("SELECT COUNT(*) AS count FROM pendingcarriertx WHERE isMempool = 1");
        while ($row = $sth->fetch(PDO::FETCH_NUM)) { $carrier_tx_count_in_db = $row[0]; }
        PHPUnit::assertEquals(0, $carrier_tx_count_in_db);

    }


    public function testFakeBTCSmallTransactionsThatAreNotCarriersConfirmLater() {
        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample data (for combined test)
        $this->initAllSampleData();
        $this->mempool_xcp_transactions = $this->buildSampleMempoolXCPTransactionsForCombined();
        $this->mempool_native_transactions = array_merge($this->buildSampleMempoolBTCTransactionsForCombined(), $this->buildSamplePreviousNativeTransactions());
        $this->mempool_native_transactions['combinedtxhash001']->txid = 'someothertxhash';
        $this->mempool_native_tx_ids = array_keys($this->buildSampleMempoolBTCTransactionsForCombined());

        // watch confirmed tx
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest01');

        // track mempool transactions
        $mempool_txs = [];
        $follower->handleMempoolTransaction(function ($transaction, $current_block_id) use (&$mempool_txs) {
            $mempool_txs[] = $transaction;
        });

        // add a BTC dust confirmed transaction
        $follower->setGenesisBlock(300000);
        $this->setCurrentBlock(300000);
        $follower->runOneIteration();

        // starts at 1 tx, because the second one looks like a carrier
        PHPUnit::assertCount(1, $mempool_txs);

        // let some time pass, and then process another iteration
        $follower->_now_timestamp = time() + 90;
        $follower->runOneIteration();

        // still 1 tx because not enough time has passed
        PHPUnit::assertCount(1, $mempool_txs);


        $follower->_now_timestamp = time() + 121;
        $follower->runOneIteration();

        // now, should see both mempool txs
        PHPUnit::assertCount(2, $mempool_txs);
    }

    // orphans wipe out pendingcarriertx
    public function testorphandWipeOutPendingCarrierTransactions() {

        // init all dbs
        $this->initAllFollowerDBs();

        // init the sample data (for combined test)
        $this->initAllSampleData();
        $this->native_transactions = $this->buildSampleBTCTransactionsForCombined();
        $this->native_transactions['A00001']->hash = 'nonxcphash01';

        // init follower
        $follower = $this->getFollower();
        $follower->addAddressToWatch('dest01');

        // run 1 blocks
        $follower->setGenesisBlock(300001);
        $this->setCurrentBlock(300001);
        $follower->runOneIteration();


        // see if combined_follower_test.pendingcarriertx has transactions
        $sth = $this->getPDO(getenv('DB_NAME'))->query("SELECT COUNT(*) AS count FROM pendingcarriertx");
        while ($row = $sth->fetch(PDO::FETCH_NUM)) { $carrier_tx_count_in_db = $row[0]; }
        PHPUnit::assertEquals(1, $carrier_tx_count_in_db);

        // no more small transactions
        $this->native_transactions['B00011'] = $this->native_transactions['B00001'];
        $this->native_transactions['B00012'] = $this->native_transactions['B00002'];
        $this->native_transactions['B00011']->out[0]->value = 10000000;
        $this->native_transactions['B00011']->hash = 'B00011';
        $this->native_transactions['B00012']->hash = 'B00012';


        // now orphan next block 300001
        $this->native_blocks = $this->getSampleNativeBlocksForReorganizedChain(1);
        $this->setCurrentBlock(300003);
        $follower->runOneIteration();

        // validate we reprocessed 300001
        PHPUnit::assertEquals(300001, $this->getNativeFollower()->getLastProcessedBlock());

        // see if combined_follower_test.pendingcarriertx is erased
        $sth = $this->getPDO(getenv('DB_NAME'))->query("SELECT COUNT(*) AS count FROM pendingcarriertx");
        while ($row = $sth->fetch(PDO::FETCH_NUM)) { $carrier_tx_count_in_db = $row[0]; }
        PHPUnit::assertEquals(0, $carrier_tx_count_in_db);

    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    
    
    protected function getFollower() {
        $follower = new Follower($this->getNativeFollower(), $this->getXCPDFollower(), $this->getConnectionManager());
        $follower->addAddressToWatch('dest01');
        return $follower;
    }

    protected function getFollowerSetup() {
        $db_name = getenv('DB_NAME');
        if (!$db_name) { throw new Exception("No DB_NAME env var found", 1); }
        return new FollowerSetup($this->getPDOWithoutDB(), $db_name);
    }

    protected function initAllFollowerDBs() {
        // native
        $db_name = getenv('NATIVE_DB_NAME');
        if (!$db_name) { throw new Exception("No NATIVE_DB_NAME env var found", 1); }
        $native_follower_setup = new \Utipd\NativeFollower\FollowerSetup($this->getPDOWithoutDB(), $db_name);
        $native_follower_setup->initializeAndEraseDatabase();
        
        // xcpd
        $db_name = getenv('XCPD_DB_NAME');
        if (!$db_name) { throw new Exception("No XCPD_DB_NAME env var found", 1); }
        $counterparty_follower_setup = new \Utipd\CounterpartyFollower\FollowerSetup($this->getPDOWithoutDB(), $db_name);
        $counterparty_follower_setup->initializeAndEraseDatabase();

        // combined
        $this->getFollowerSetup()->initializeAndEraseDatabase();
    }

    protected function getPDO($db_name=null) {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(true, $db_name);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    protected function getPDOWithoutDB() {
        list($db_connection_string, $db_user, $db_password) = $this->buildConnectionInfo(false);
        $pdo = new \PDO($db_connection_string, $db_user, $db_password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    protected function getConnectionManager() {
        list($db_connection_string, $db_user, $db_password, $db_name) = $this->buildConnectionInfo(true);
        $manager = new ConnectionManager($db_connection_string, $db_user, $db_password);
        return $manager;
    }

    protected function getNativeFollower() {
        if (!isset($this->native_follower)) {
            $db_name = getenv('NATIVE_DB_NAME');
            if (!$db_name) { throw new Exception("No NATIVE_DB_NAME env var found", 1); }

            $this->native_follower = new \Utipd\NativeFollower\Follower($this->getMockNativeClient(), $this->getPDO($db_name), $this->getMockGuzzleClient());
        }
        return $this->native_follower;
    }

    protected function getXCPDFollower() {
        if (!isset($this->xcpd_follower)) {
            $db_name = getenv('XCPD_DB_NAME');
            if (!$db_name) { throw new Exception("No XCPD_DB_NAME env var found", 1); }

            $this->xcpd_follower = new \Utipd\CounterpartyFollower\Follower($this->getMockXCPDClient(), $this->getPDO($db_name));
        }
        return $this->xcpd_follower;
    }

    protected function buildConnectionInfo($with_db=true, $db_name=null) {
        if ($db_name === null) {
            $db_name = getenv('DB_NAME');
            if (!$db_name) { throw new Exception("No DB_NAME env var found", 1); }
        }
        $db_host = getenv('DB_HOST');
        if (!$db_host) { throw new Exception("No DB_HOST env var found", 1); }
        $db_port = getenv('DB_PORT');
        if (!$db_port) { throw new Exception("No DB_PORT env var found", 1); }
        $db_user = getenv('DB_USER');
        if (!$db_user) { throw new Exception("No DB_USER env var found", 1); }
        $db_password = getenv('DB_PASSWORD');
        if ($db_password === false) { throw new Exception("No DB_PASSWORD env var found", 1); }

        if ($with_db) {
            $db_connection_string = "mysql:dbname={$db_name};host={$db_host};port={$db_port}";
        } else {
            $db_connection_string = "mysql:host={$db_host};port={$db_port}";
        }

        return [$db_connection_string, $db_user, $db_password, $db_name];
    }

    protected function getMockXCPDClient() {
        if (!isset($this->xcpd_client)) {
            $this->xcpd_client = new \Utipd\CounterpartyFollower\Mocks\MockClient();
            $this->xcpd_client
                ->addCallback('get_running_info', function() {
                    return $this->xcp_running_info;
                })
                ->addCallback('get_sends', function($vars) {
                    if (isset($this->xcp_transactions[$vars['start_block']])) {
                        return [$this->xcp_transactions[$vars['start_block']]];
                    }

                    // no sends
                    return [];
                })->addCallback('get_mempool', function() {
                    return $this->mempool_xcp_transactions;
                });
        }
        return $this->xcpd_client;
    }

    protected function getMockNativeClient() {
        if (!isset($this->native_client)) {
            $this->native_client = new \Utipd\NativeFollower\Mocks\MockClient();
            $this->native_client
                ->addCallback('getblockcount', function() {
                    return $this->native_block_height;
                })
                ->addCallback('getblockhash', function($block_id) {
                    return $this->native_blocks[$block_id]['hash'];
                })
                ->addCallback('getblock', function($block_hash_params) {
                    list($block_hash, $verbose) = $block_hash_params;
                    $blocks = $this->native_blocks;
                    foreach ($blocks as $block) {
                        if ($block['hash'] == $block_hash) { return (object)$block; }
                    }
                    throw new Exception("Block not found: $block_hash", 1);
                })
                ->addCallback('getrawmempool', function() {
                    return $this->mempool_native_tx_ids;
                })->addCallback('getrawtransaction', function($tx_id_params) {
                    $tx_id = $tx_id_params[0];
                    return $this->mempool_native_transactions[$tx_id];
                });
        }
        return $this->native_client;
    }

    protected function getMockGuzzleClient() {
        if (!isset($this->mock_guzzle_client)) {

            $guzzle = $this->getMockBuilder('\GuzzleHttp\Client')
                     ->disableOriginalConstructor()
                     ->getMock();

            $guzzle->method('get')->will($this->returnCallback(function($url) {
                $hash = array_slice(explode('/', $url), -1)[0];

                foreach ($this->native_blocks as $sample_block) {
                    if ($sample_block['hash'] == $hash) {
                        $sample_block = $this->applyTransactionsToSampleBlock($sample_block);
                        return new \GuzzleHttp\Message\Response(200, ['Content-Type' => 'application/json'], \GuzzleHttp\Stream\Stream::factory(json_encode($sample_block)));
                    }
                }

                throw new Exception("sample block not found with hash $hash", 1);
            }));

            $this->mock_guzzle_client = $guzzle;
        }
        return $this->mock_guzzle_client;
    }

    ////////////////////////////////////////////////////////////////////////
    /// sample data

    protected function initAllSampleData() {
        $this->native_block_height = 300002;

        $this->mempool_native_transactions = array_merge($this->buildSampleMempoolTransactions(), $this->buildSamplePreviousNativeTransactions());
        $this->mempool_native_tx_ids = $this->buildSampleMempoolNativeTransactionIDs();

        $this->native_blocks = $this->getSampleNativeBlocks();
        $this->native_transactions = $this->getSampleTransactionsForBlockchainInfo();
        

        $this->xcp_running_info = ['bitcoin_block_count' => 300002, 'last_block' => ['block_index' => 300002]];

        $this->mempool_xcp_transactions = $this->buildSampleMempoolXCPTransactions();
        $this->xcp_transactions = $this->getSampleXCPSends();
    }

    protected function setCurrentBlock($xcp_block_height, $native_block_height=null) {
        $this->xcp_running_info['last_block']['block_index'] = $xcp_block_height;
        if ($native_block_height === null) { $native_block_height = $xcp_block_height; }
        $this->native_block_height = $native_block_height;
    }

    ////////////////////////////////////////////////////////////////////////
    /// sample data

    protected function getSampleXCPSends() {
        return [
            "300000" => [
                // fake info
                "block_index" => 300000,
                "tx_index"    => 100000,
                "source"      => "1NFeBp9s5aQ1iZ26uWyiK2AYUXHxs7bFmB",
                "destination" => "dest01",
                "asset"       => "XCP",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "hash1",
            ],
            "300001" => [
                // fake info
                "block_index" => 300001,
                "tx_index"    => 100001,
                "source"      => "source2",
                "destination" => "dest02",
                "asset"       => "ASSET2",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "hash2",
            ]
        ];
    }

    protected function buildSampleMempoolXCPTransactions() {
        return json_decode($_j = <<<EOT
                        [
                            {
                                "bindings": "{\"asset\": \"MYASSETONE\", \"destination\": \"dest01\", \"quantity\": 10, \"source\": \"13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn\", \"tx_hash\": \"mempool01txhash\"}",
                                "category": "sends",
                                "command": "insert",
                                "timestamp": 1407585745,
                                "tx_hash": "mempool01txhash"
                            }
                        ]
EOT
                        , true);
    }

    protected function getSampleNativeBlocks() {
        return [
            "300000" => [
                "previousblockhash" => "BLK_NORM_G099",
                "hash"              => "BLK_NORM_A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "300001" => [

                "previousblockhash" => "BLK_NORM_A100",
                "hash"              => "BLK_NORM_B101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "300002" => [

                "previousblockhash" => "BLK_NORM_B101",
                "hash"              => "BLK_NORM_C102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "300003" => [

                "previousblockhash" => "BLK_NORM_C102",
                "hash"              => "BLK_NORM_D103",
                "tx" => [
                    "D00001",
                    "D00002",
                ],
            ],
            "300004" => [

                "previousblockhash" => "BLK_NORM_D103",
                "hash"              => "BLK_NORM_E103",
                "tx" => [
                    "E00001",
                    "E00002",
                ],
            ],
            "300005" => [

                "previousblockhash" => "BLK_NORM_E103",
                "hash"              => "BLK_NORM_F103",
                "tx" => [
                    "F00001",
                    "F00002",
                ],
            ],
            "300006" => [

                "previousblockhash" => "BLK_NORM_F103",
                "hash"              => "BLK_NORM_G103",
                "tx" => [
                    "G00001",
                    "G00002",
                ],
            ],
        ];
    }

    protected function getSampleNativeBlocksForReorganizedChain($tx_modifier=null) {
        $txs = [
            "300000" => [
                "previousblockhash" => "BLK_NORM_G099",
                "hash"              => "BLK_NORM_A100",
                "tx" => [
                    "A00001",
                    "A00002",
                ],
            ],
            "300001" => [

                "previousblockhash" => "BLK_NORM_A100",
                "hash"              => "BLK_REORG_B101",
                "tx" => [
                    "B00001",
                    "B00002",
                ],
            ],
            "300002" => [

                "previousblockhash" => "BLK_REORG_B101",
                "hash"              => "BLK_REORG_C102",
                "tx" => [
                    "C00001",
                    "C00002",
                ],
            ],
            "300003" => [

                "previousblockhash" => "BLK_REORG_C102",
                "hash"              => "BLK_REORG_D103",
                "tx" => [
                    "D00001",
                    "D00002",
                ],
            ],
        ];

        if ($tx_modifier !== null) {
            $out = [];
            foreach($txs as $id => $tx) {
                $tx['tx'][0] = substr($tx['tx'][0], 0, 4).((string)$tx_modifier).substr($tx['tx'][0], 5);
                $tx['tx'][1] = substr($tx['tx'][1], 0, 4).((string)$tx_modifier).substr($tx['tx'][1], 5);
                $out[$id] = $tx;
            }
            $txs = $out;
        }

        return $txs;
    }

    protected function applyTransactionsToSampleBlock($block) {
        $sample_transactions = $this->native_transactions;
        $out = $block;
        $out['tx'] = [];
        foreach($block['tx'] as $tx_id) {
            $out['tx'][] = $sample_transactions[$tx_id];
        }
        return $out;
    }

    protected function getSampleTransactionsForBlockchainInfo() {
        
        if (!isset($this->sample_txs)) {
        $samples =  [
            // ################################################################################################
            "A00001" => json_decode($_j = <<<EOT
{
    "hash": "A00001",
    "inputs": [
        {
            "prev_out": {
                "addr": "1J4gVXjd1CT2NnGFkmzaJJvNu4GVUfYLVK",
                "n": 1,
                "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac",
                "txid": 22961584,
                "type": 0,
                "value": 56870000
            },
            "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac"
        }
    ],
    "out": [
        {
            "addr": "15jhGQfARmEuh8JY73QwrgxYCGhqWAMkAC",
            "n": 0,
            "script": "210231a3996818ce0d955279421e4f0c4bd07502b9c03c135409e3189c0e067cbb9bac",
            "spent": false,
            "txid": 24736715,
            "type": 0,
            "value": 56860000
        }
    ],
    "relayed_by": "24.210.191.129",
    "size": 203,
    "time": 1376370366,
    "txid": 24736715,
    "ver": 1,
    "vin_sz": 1,
    "vout_sz": 1
}

EOT
            ),
            // ################################################################################################
            "A00002" => json_decode($_j = <<<EOT
{
    "hash": "A00002",
    "inputs": [
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 116,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "txid": 26175189,
                "type": 0,
                "value": 880
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        },
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 0,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "txid": 27968848,
                "type": 0,
                "value": 59000000
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        }
    ],
    "out": [
        {
            "addr": "1AEwxRGP4HdwrwoXo1rEKc3jihFmZUybCw",
            "n": 0,
            "script": "2102f4e9b26c2e0e86761e411e03ffd7b15b8ca4dbea464fb8a4a5dab4220e602c64ac",
            "spent": true,
            "txid": 30536227,
            "type": 0,
            "value": 58990880
        }
    ],
    "relayed_by": "85.17.239.32",
    "size": 349,
    "time": 1376370307,
    "txid": 30536227,
    "ver": 1,
    "vin_sz": 2,
    "vout_sz": 1
}


EOT
            ),
            // ################################################################################################
        ];

            // copy to 
            foreach (['B00001','B00002','C00001','C00002','D00001','D00002','E00001','E00002','F00001','F00002','G00001','G00002',] as $new_id) {
                $samples[$new_id] = clone $samples["A0000".substr($new_id, -1)];
                $samples[$new_id]->hash = $new_id;
            }
            $this->sample_txs = $samples;
        }

        return $this->sample_txs;
    }

    protected function buildSampleMempoolNativeTransactionIDs() {
        $out = array_keys($this->buildSampleMempoolTransactions());
        return $out;
    }



    protected function buildSampleMempoolTransactions() {
            $native_mempool_txs =  [
                // ################################################################################################
                "rawtxid001" => json_decode($_j = <<<EOT
{
    "txid": "rawtxid001",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.5,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "dest01"
                ]
            }
        },
        {
            "value": 0.02233228,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
            ),
                "rawtxid002" => json_decode($_j = <<<EOT
{
    "txid": "rawtxid002",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.6,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "dest01"
                ]
            }
        },
        {
            "value": 0.08,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
                ),

            ];
        return $native_mempool_txs;
    }

    protected function buildSamplePreviousNativeTransactions() {
            $previous_txs =  [

                // ################################################################################
                // # Previous TX
                "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6" => json_decode($_j = <<<EOT
{
    "hex": "01000000023956983b8b83f89fbab7351f0a7b2215898e111f94609d145ea83df9e8aa8697000000008b483045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e00141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80ffffffffc4464286201abca2421877f8ff5d9c27a168d3fefaca6fab2b5d1e8c0d0a531e000000008b483045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80ffffffff026011aa02000000001976a9140fdccbddf363a82392193e7ef11fa743e66cc85488ac905f0100000000001976a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac00000000",
    "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "9786aae8f93da85e149d60941f118e8915227b0a1f35b7ba9ff8838b3b985639",
            "vout": 0,
            "scriptSig": {
                "asm": "3045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e001 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022100e4bdce70fa78362f610eba9c634d2fbfcd840239a0d11bbe9d9fef320d249d7e022037592aa957a639913408421c4896f2881bb97cc91747b6e1aaf421fad3bbe5e00141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        },
        {
            "txid": "1e530a0d8c1e5d2bab6fcafafed368a1279c5dfff8771842a2bc1a20864246c4",
            "vout": 0,
            "scriptSig": {
                "asm": "3045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022100e78ac9a5e63064980110bd70c8953a8e0033d5ff4415ac12d4f8d10702f79986022078ef90005b93a46b918d8799057d04151bd311752e04dbcd841d4ce49a17c2cb0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.447,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 0fdccbddf363a82392193e7ef11fa743e66cc854 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a9140fdccbddf363a82392193e7ef11fa743e66cc85488ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "12Sse5UeBLLKKRVmSgwQzNehtFPbBdn4n8"
                ]
            }
        },
        {
            "value": 0.0009,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ],
    "blockhash": "000000000000000021a5dfd8763c64498c1087a07b2551d4d1b6362f3a5f1133",
    "confirmations": 12601,
    "time": 1402538244,
    "blocktime": 1402538244
}
EOT
                ),
            ];
        return $previous_txs;
    }


    ////////////////////////////////////////////////////////////////////////


    protected function buildSampleMempoolXCPTransactionsForCombined() {
        return json_decode($_j = <<<EOT
                        [
                            {
                                "bindings": "{\"asset\": \"MYASSETONE\", \"destination\": \"dest01\", \"quantity\": 10, \"source\": \"13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn\", \"tx_hash\": \"combinedtxhash001\"}",
                                "category": "sends",
                                "command": "insert",
                                "timestamp": 1407585745,
                                "tx_hash": "combinedtxhash001"
                            }
                        ]
EOT
                        , true);
    }

    protected function buildSampleMempoolBTCTransactionsForCombined() {
            $native_mempool_txs =  [
                // ################################################################################################
                "combinedtxhash001" => json_decode($_j = <<<EOT
{
    "txid": "combinedtxhash001",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.000078,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "dest01"
                ]
            }
        },
        {
            "value": 0.02233228,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
            ),
                "rawtxid002" => json_decode($_j = <<<EOT
{
    "txid": "rawtxid002",
    "version": 1,
    "locktime": 0,
    "vin": [
        {
            "txid": "ebbb76c12c4de2207fa482958e1eafa13fcee9ead64a616bdb01e00b37cb52a6",
            "vout": 1,
            "scriptSig": {
                "asm": "3045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c01 046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80",
                "hex": "483045022074f04cfad743082a16fb0f005ad3ff7311a4fe6f952b267110af6b7e56f0a89c022100a8d3b3075328e2e220cd3abda147b94a97112c4b41dc11227ba669597cb4cc3c0141046af1d67a6b4db50d61bb0e3a4bf855f01d35be157476d04b5942e1b732956457db009ffa621bb8122b53818033f6996b5c63081e24f9770d6d2fe4408e9afc80"
            },
            "sequence": 4294967295
        }
    ],
    "vout": [
        {
            "value": 0.000078,
            "n": 0,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 c153c44c7e86b4040680bfbda69dc7f6400123ea OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a914c153c44c7e86b4040680bfbda69dc7f6400123ea88ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "dest02"
                ]
            }
        },
        {
            "value": 0.08,
            "n": 1,
            "scriptPubKey": {
                "asm": "OP_DUP OP_HASH160 78af7d849c3c4767584502bd22ddf2b642c2eb20 OP_EQUALVERIFY OP_CHECKSIG",
                "hex": "76a91478af7d849c3c4767584502bd22ddf2b642c2eb2088ac",
                "reqSigs": 1,
                "type": "pubkeyhash",
                "addresses": [
                    "1C18KJPUfAmsaqTUiJ4VujzMz37MM3W2AJ"
                ]
            }
        }
    ]
}

EOT
                ),
            ];
        return $native_mempool_txs;
    }    
    
    ////////////////////////////////////////////////////////////////////////
    // Combined Confirmed Transactions

    protected function buildSampleXCPTransactionsForCombined() {
        return [
            "300000" => [
                // fake info
                "block_index" => 300000,
                "tx_index"    => 100000,
                "source"      => "1NFeBp9s5aQ1iZ26uWyiK2AYUXHxs7bFmB",
                "destination" => "dest01",
                "asset"       => "XCP",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "hash1",
            ],
            "300001" => [
                // fake info
                "block_index" => 300001,
                "tx_index"    => 100001,
                "source"      => "source2",
                "destination" => "dest02",
                "asset"       => "ASSET2",
                "status"      => "valid",
                "quantity"    => 490000000,
                "tx_hash"     => "hash2",
            ]
        ];
    }

    protected function buildSampleBTCTransactionsForCombined() {
        $samples =  [
            // ################################################################################################
            "A00001" => json_decode($_j = <<<EOT
{
    "hash": "hash1",
    "inputs": [
        {
            "prev_out": {
                "addr": "1J4gVXjd1CT2NnGFkmzaJJvNu4GVUfYLVK",
                "n": 1,
                "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac",
                "txid": 22961584,
                "type": 0,
                "value": 56870000
            },
            "script": "76a914bb2c5a35cc23ad967773b6734ce956b8ded8cf2388ac"
        }
    ],
    "out": [
        {
            "addr": "dest01",
            "n": 0,
            "script": "210231a3996818ce0d955279421e4f0c4bd07502b9c03c135409e3189c0e067cbb9bac",
            "spent": false,
            "txid": 24736715,
            "type": 0,
            "value": 7800
        }
    ],
    "relayed_by": "24.210.191.129",
    "size": 203,
    "time": 1376370366,
    "txid": 24736715,
    "ver": 1,
    "vin_sz": 1,
    "vout_sz": 1
}

EOT
            ),
            // ################################################################################################
            "A00002" => json_decode($_j = <<<EOT
{
    "hash": "hash2",
    "inputs": [
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 116,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "txid": 26175189,
                "type": 0,
                "value": 880
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        },
        {
            "prev_out": {
                "addr": "14nJzbZHueWg5VHa4bDFQ2yxx4pbCDaEvL",
                "n": 0,
                "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac",
                "txid": 27968848,
                "type": 0,
                "value": 59000000
            },
            "script": "76a914297a1d8ea54f8ef26f524597a187a83c708cf07f88ac"
        }
    ],
    "out": [
        {
            "addr": "dest02",
            "n": 0,
            "script": "2102f4e9b26c2e0e86761e411e03ffd7b15b8ca4dbea464fb8a4a5dab4220e602c64ac",
            "spent": true,
            "txid": 30536227,
            "type": 0,
            "value": 7800
        }
    ],
    "relayed_by": "85.17.239.32",
    "size": 349,
    "time": 1376370307,
    "txid": 30536227,
    "ver": 1,
    "vin_sz": 2,
    "vout_sz": 1
}


EOT
            ),
            // ################################################################################################
        ];

        // copy to 
        foreach (['B00001','B00002','C00001','C00002','D00001','D00002','E00001','E00002','F00001','F00002','G00001','G00002',] as $new_id) {
            $samples[$new_id] = clone $samples["A0000".substr($new_id, -1)];
            $samples[$new_id]->hash = $new_id;
        }

        return $samples;
    }    
    

}
