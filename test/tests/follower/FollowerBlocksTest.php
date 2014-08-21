<?php

use Utipd\CombinedFollower\Follower;
use Utipd\CombinedFollower\FollowerSetup;
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

        // check blocks found
        PHPUnit::assertCount(2, $xcp_txs_map);
        PHPUnit::assertCount(2, $native_txs_map);

        PHPUnit::assertEquals('13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn', $xcp_txs_map['300000'][0]['source']);
        PHPUnit::assertEquals('13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn', $xcp_txs_map['300001'][0]['source']);
        PHPUnit::assertEquals(0.5*100000000, $native_txs_map['300000'][0]['quantity']);
        PHPUnit::assertEquals(0.6*100000000, $native_txs_map['300000'][1]['quantity']);
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

        // echo "\$xcp_txs:\n".json_encode($xcp_txs, 192)."\n";
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



    ////////////////////////////////////////////////////////////////////////
    
    
    protected function getFollower() {
        $follower = new Follower($this->getNativeFollower(), $this->getXCPDFollower(), $this->getPDO());
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
                    if (isset($this->xcp_sends[$vars['start_block']])) {
                        return [$this->xcp_sends[$vars['start_block']]];
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

    protected function initAllSampleData() {
        $this->native_blocks = $this->getSampleNativeBlocks();
        $this->native_txs = $this->getSampleTransactionsForBlockchainInfo();
        $this->xcp_running_info = ['bitcoin_block_count' => 300002, 'last_block' => ['block_index' => 300002]];
        $this->native_block_height = 300002;
        $this->xcp_sends = $this->getSampleXCPSends();
        $this->mempool_xcp_transactions = $this->buildSampleMempoolXCPTransactions();
        $this->mempool_native_tx_ids = $this->buildSampleMempoolNativeTransactionIDs();
        $this->mempool_native_transactions = $this->buildSampleMempoolTransactions();
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
                                "bindings": "{\"asset\": \"MYASSETONE\", \"destination\": \"dest01\", \"quantity\": 10, \"source\": \"13UxmTs2Ad2CpMGvLJu3tSV2YVuiNcVkvn\", \"tx_hash\": \"c324e62d0ba17f42a774b9b28114217c777914a4b6dd0d41811217cffb8c40a6\"}",
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

    protected function getSampleNativeBlocksForReorganizedChain() {
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
    }

    protected function applyTransactionsToSampleBlock($block) {
        $sample_transactions = $this->native_txs;
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
        return array_keys($this->buildSampleMempoolTransactions());
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

}
