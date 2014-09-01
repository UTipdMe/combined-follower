<?php 

namespace Utipd\CombinedFollower;

use Emailme\Currency\CurrencyUtil;
use PDO;
use Utipd\CombinedFollower\Models\Directory\BlockchainTransactionDirectory;
use Utipd\CombinedFollower\Models\Directory\WatchAddressDirectory;
use Utipd\MysqlModel\ConnectionManager;

// TODO: suppress dust BTC transactions that are actually XCP transactions

/**
*       
*/
class Follower
{

    // no blocks before this are ever seen
    protected $genesis_block = 314170;

    protected $new_native_block_callback_fn;
    protected $new_xcpd_block_callback_fn;
    protected $mempool_tx_callback_fn;
    protected $confirmed_tx_callback_fn;
    protected $orphaned_block_callback_fn;
    protected $orphaned_transaction_callback_fn;

    protected $max_confirmations_for_confirmed_tx = 6;

    protected $BTC_DUST_TIMEOUT_TTL = 120; // after 120 seconds, we assume a BTC dust transaction was not matched by counterparty

    var $_now_timestamp = null; // for testing

    function __construct($native_follower, $xcpd_follower, ConnectionManager $connection_manager) {
        $this->native_follower = $native_follower;
        $this->xcpd_follower = $xcpd_follower;
        $this->connection_manager = $connection_manager;

        // init directories
        $this->blockchain_tx_directory = new BlockchainTransactionDirectory($connection_manager);

        // init default genesis block for followers
        $this->xcpd_follower->setGenesisBlock($this->genesis_block);
        $this->native_follower->setGenesisBlock($this->genesis_block);

        // initialize the callbacks
        $this->init();
    }

    public function setGenesisBlock($genesis_block) {
        $this->xcpd_follower->setGenesisBlock($genesis_block);
        $this->native_follower->setGenesisBlock($genesis_block);
    }


    ////////////////////////////////////////////////////////////////////////
    // watch addresses

    public function addAddressToWatch($bitcoin_address) {
        $result = $this->getDBConnection()->prepare("REPLACE INTO watchaddress VALUES (?)")->execute([$bitcoin_address]);
    }

    public function removeAddressToWatch($bitcoin_address) {
        $result = $this->getDBConnection()->prepare("DELETE FROM watchaddress WHERE address = ?")->execute([$bitcoin_address]);
    }

    public function clearAllAddressesToWatch() {
        $result = $this->getDBConnection()->exec("TRUNCATE watchaddress");
    }



    ////////////////////////////////////////////////////////////////////////
    // Handlers
    
    // function ($block_id) {}
    public function handleNewNativeBlock(callable $callback_fn) {
        $this->new_native_block_callback_fn = $callback_fn;
    }

    // function ($block_id) {}
    public function handleNewCounterpartyBlock(callable $callback_fn) {
        $this->new_xcpd_block_callback_fn = $callback_fn;
    }

    // function ($orphaned_block_id) {}
    public function handleOrphanedBlock(callable $callback_fn) {
        $this->orphaned_block_callback_fn = $callback_fn;
    }

    // function ($transaction) {}
    public function handleOrphanedTransaction(callable $callback_fn) {
        $this->orphaned_transaction_callback_fn = $callback_fn;
    }

    // function ($transaction, $current_block_id) {}
    public function handleMempoolTransaction(callable $callback_fn) {
        $this->mempool_tx_callback_fn = $callback_fn;
    }
    
    // function ($transaction, $number_of_confirmations, $current_block_id) {}
    public function handleConfirmedTransaction(callable $callback_fn) {
        $this->confirmed_tx_callback_fn = $callback_fn;
    }

    public function setMaxConfirmationsForConfirmedCallback($max_confirmations) {
        $this->max_confirmations_for_confirmed_tx = $max_confirmations;
    }
    
    ////////////////////////////////////////////////////////////////////////
    // Setup

    public function runOneIteration() {
        $this->native_follower->processOneNewBlock();
        $this->xcpd_follower->processOneNewBlock();

        // find any timed unmatched BTC dust transactions
        $this->handleTimedOutBTCDustTransactions();
    }


    // this can return a null or a new transaction
    public function createNewTransaction($send_data, $is_native, $is_mempool, $current_block_id) {
        // if this is a mempool transaction, be sure not to delete a transaction
        if ($is_mempool) {
            $existing_live_blockchain_tx_entry = $this->blockchain_tx_directory->findOne(['tx_hash' => $send_data['tx_hash'], 'isNative' => $is_native ? 1 : 0, 'isMempool' => 0]);

            if ($existing_live_blockchain_tx_entry) {
                // this is a mempool transaction arriving AFTER a live version of the transaction has already been recorded
                //   we never want to erase a live transaction with its mempool equivalent
                //   so ignore this
                return null;
            }
        }

        // delete any existing transactions with this tx_hash
        $this->blockchain_tx_directory->deleteWhere(['tx_hash' => $send_data['tx_hash'], 'isNative' => $is_native ? 1 : 0]);


        // create a new transaction
        $new_transaction = $this->blockchain_tx_directory->createAndSave([
            'transactionId'  => $is_mempool ? $send_data['tx_hash'] : $send_data['tx_index'],
            'blockId'        => $is_mempool ? $current_block_id : $send_data['block_index'],
            'tx_hash'        => $send_data['tx_hash'],

            'isNative'       => $is_native,
            'isMempool'      => $is_mempool,
            'destination'    => $send_data['destination'],

            'source'         => $send_data['source'],
            'asset'          => $send_data['asset'],
            'quantity'       => $send_data['quantity'],
            // 'status'         => $is_mempool ? 'mempool' : $send_data['status'],

            'timestamp'      => time(),
        ]);
        return $new_transaction;
    }


    ////////////////////////////////////////////////////////////////////////
    // Transactions
    
    public function allTransactionsToDestination($destination_address) {
        return $this->blockchain_tx_directory->find(['destination' => $destination_address], ['isMempool' => 1, 'id' => 1]);
    }

    ////////////////////////////////////////////////////////////////////////


    protected function init() {
        $this->setupXCPDFollowerCallbacks();
        $this->setupNativeFollowerCallbacks();
    }

    protected function setupXCPDFollowerCallbacks() {
        $this->xcpd_follower->handleNewBlock(function($block_id) {
            // a new block was found
            // clear all mempool transactions
            $this->clearAllMempoolTransactions($native=false);

            // callback
            if (isset($this->new_xcpd_block_callback_fn)) {
                $f = $this->new_xcpd_block_callback_fn;
                $f($block_id);
            }

            // now check every confirmed send withing max_confirmations blocks and call the callback
            $this->invokeConfirmedTransactionCallbacks($block_id, $is_native=false);
        });

        $this->xcpd_follower->handleNewSend(function($send_data, $block_id, $is_mempool) {
            // we have a new counterparty send
            if ($is_mempool) {
                $current_block_id = $this->xcpd_follower->getLastProcessedBlock();
                if ($current_block_id === null) { $current_block_id = $block_id; }
            } else {
                $current_block_id = $block_id;
            }

            if ($this->isWatchAddress($send_data['destination'])) {
                // the asset is not divisible, then the quantity will not be in satoshis
                if (!$send_data['assetInfo']['divisible']) {
                    // convert to satoshis for consistency
                    $send_data['quantity'] =  CurrencyUtil::numberToSatoshis($send_data['quantity']);
                }

                // handle a new send
                $transaction = $this->createNewTransaction($send_data, $is_native=false, $is_mempool, $current_block_id);
                $this->invokeNewTransactionCallbacks($transaction, $is_native=false, $is_mempool, $current_block_id);
            }
        });
    }

    protected function setupNativeFollowerCallbacks() {
        $this->native_follower->handleNewBlock(function($block_id) {
            // a new block was found
            // clear all mempool transactions
            $this->clearAllMempoolTransactions($native=true);

            // callback
            if (isset($this->new_native_block_callback_fn)) {
                $f = $this->new_native_block_callback_fn;
                $f($block_id);
            }

            // clear all mempool based pending carrier transactions
            $this->clearMempoolXCPCarrierTransactions();

            // now check every confirmed send withing max_confirmations blocks and call the callback
            $this->invokeConfirmedTransactionCallbacks($block_id, $is_native=true);
        });

        $this->native_follower->handleNewTransaction(function($native_tx, $block_id, $is_mempool) {
            // a new transaction
            // txid: cc91db2f18b908903cb7c7a4474695016e12afd816f66a209e80b7511b29bba9
            // outputs:
            //     - amount: 100000
            //       address: "1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            if ($is_mempool) {
                $current_block_id = $this->native_follower->getLastProcessedBlock();
                if ($current_block_id === null) { $current_block_id = $block_id; }
            } else {
                $current_block_id = $block_id;
            }


            // find the accounts that we care about watching
            $all_watch_addresses_map = $this->buildWatchAddressMap();

            foreach ($native_tx['outputs'] as $output) {
                if (!$output['address']) { continue; }

                $destination_address = $output['address'];

                // do we care about this destination address?
                if (isset($all_watch_addresses_map[$destination_address])) {
                    $sources_map = [];
                    foreach ($native_tx['inputs'] as $input) {
                        $sources_map[$input['address']] = true;
                    }

                    $btc_send_data = [];
                    $btc_send_data['tx_index']    = $native_tx['txid'];
                    $btc_send_data['block_index'] = $block_id;
                    $btc_send_data['source']      = array_keys($sources_map);
                    $btc_send_data['destination'] = $destination_address;
                    $btc_send_data['asset']       = 'BTC';
                    $btc_send_data['quantity']    = $output['amount']; // already in satoshis
                    $btc_send_data['status']      = 'valid';
                    $btc_send_data['tx_hash']     = $native_tx['txid'];

                    $transaction_model = $this->createNewTransaction($btc_send_data, $is_native=true, $is_mempool, $current_block_id);
                    $this->invokeNewTransactionCallbacks($transaction_model, $is_native=true, $is_mempool, $current_block_id);
                }
            }
        });

        $this->native_follower->handleOrphanedBlock(function($orphaned_block_id) {
            if (isset($this->orphaned_transaction_callback_fn)) {
                $orphaned_transactions = iterator_to_array($this->blockchain_tx_directory->find(['blockId' => $orphaned_block_id, 'isMempool' => false]));
            }

            // delete transactions
            $this->blockchain_tx_directory->deleteWhere(['blockId' => $orphaned_block_id]);

            // tell the counterparty follower to orphan this block
            $this->xcpd_follower->orphanBlock($orphaned_block_id);


            // callback the orphaned block callback function
            if (isset($this->orphaned_block_callback_fn)) {
                $f = $this->orphaned_block_callback_fn;
                $f($orphaned_block_id);
            }

            // callback the orphaned transactions callbacks
            if (isset($this->orphaned_transaction_callback_fn)) {
                $f = $this->orphaned_transaction_callback_fn;
                foreach($orphaned_transactions as $orphaned_transaction) {
                    $f($orphaned_transaction);
                }
            }

            // clear orphaned pending transactions
            $this->clearMempoolXCPCarrierTransactionsByBlockID($orphaned_block_id);

        });
    }


    protected function clearAllMempoolTransactions($is_native) {
        $this->blockchain_tx_directory->deleteRaw("DELETE FROM {$this->blockchain_tx_directory->getTableName()} WHERE isMempool = ? AND isNative = ?", [1, intval($is_native)]);
    }

    ////////////////////////////////////////////////////////////////////////
    // callbacks
    
    protected function invokeNewTransactionCallbacks($transaction, $is_native, $is_mempool, $current_block_id) {
        $is_carrier_transaction = false;
        $skip_callback = false;

        // this is a confirmed BTC carrier transaction for a Counterparty transaction
        if ($is_native AND $this->isXCPCarrierTransaction($transaction['tx_hash'])) {
            $is_carrier_transaction = true;
            $skip_callback = true;
        }

        // if this looks like a carrier transaction, don't do the callback (yet)
        if (!$is_carrier_transaction) {
            if ($is_native AND $this->looksLikeXCPCarrierTransaction($transaction)) {
                $skip_callback = true;
                $this->savePendingXCPCarrierTransaction($transaction['tx_hash'], $current_block_id, $is_mempool);
            }
        }

        // call new transaction callbacks
        $number_of_confirmations = ($is_mempool ? 0 : 1);
        if (!$skip_callback) {
            $was_triggered = $this->callNewTransactionCallbacks($transaction, $is_native, $is_mempool, $current_block_id, $number_of_confirmations);
            if ($was_triggered) {
                $this->markCallbackTriggered($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations, $current_block_id);
            }
        }
    }

    protected function callNewTransactionCallbacks($transaction, $is_native, $is_mempool, $current_block_id, $number_of_confirmations) {
        $was_triggered = true;
        if ($is_mempool) {
            // mempool
            if (isset($this->mempool_tx_callback_fn)) {
                // print "shouldTriggerCallback: {$transaction['tx_hash']} for dest {$transaction['destination']}: ".($this->shouldTriggerCallback($transaction['tx_hash'], $number_of_confirmations) ? 'TRUE' : 'FALSE')."\n";
                if ($this->shouldTriggerCallback($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations)) {
                    $f = $this->mempool_tx_callback_fn;
                    $f($transaction, $current_block_id);
                    $was_triggered = true;
                }
            }
        } else {
            // confirmed
            if (isset($this->confirmed_tx_callback_fn)) {
                if ($this->shouldTriggerCallback($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations)) {
                    $f = $this->confirmed_tx_callback_fn;
                    $f($transaction, $number_of_confirmations, $current_block_id);
                    $was_triggered = true;
                } 
            }
        }
        return $was_triggered;

    }

    // check every confirmed send withing max_confirmations blocks and call the callback
    protected function invokeConfirmedTransactionCallbacks($block_id, $is_native) {
        // don't bother if we don't have a callback assigned
        if ($this->confirmed_tx_callback_fn === null) { return; }

        $max_block_id = $block_id - $this->max_confirmations_for_confirmed_tx + 1;
        $sql = "SELECT tx.* FROM {$this->blockchain_tx_directory->getTableName()} tx, watchaddress a ".
            "WHERE tx.blockId >= ? ".
            "AND tx.blockId <= ? ".
            "AND tx.isMempool = 0 ".
            "AND tx.isNative = ? ".
            "AND a.address = tx.destination ".
            "ORDER BY tx.id";
        $transactions = $this->blockchain_tx_directory->findRaw($sql, [$max_block_id, $block_id, intval($is_native)]);
        $f = $this->confirmed_tx_callback_fn;
        foreach($transactions as $transaction) {
            $is_carrier_transaction = false;
            $skip_callback = false;

            // this is a confirmed BTC carrier transaction for a Counterparty transaction
            if ($is_native AND $this->isXCPCarrierTransaction($transaction['tx_hash'])) {
                $is_carrier_transaction = true;
                $skip_callback = true;
            }

            // if this looks like a carrier transaction, don't do the callback (yet)
            if (!$is_carrier_transaction) {
                if ($is_native AND $this->looksLikeXCPCarrierTransaction($transaction)) {
                    $skip_callback = true;
                    $this->savePendingXCPCarrierTransaction($transaction['tx_hash'], $block_id, false);
                }
            }

            $confirmations_in_db = $block_id - $transaction['blockId'] + 1;
            $max_number_of_confirmations = min($confirmations_in_db, $this->max_confirmations_for_confirmed_tx);

            // trigger every confirmation up to $this->max_confirmations_for_confirmed_tx
            for ($i=0; $i < $max_number_of_confirmations; $i++) { 
                $number_of_confirmations = $i + 1;
                if (!$skip_callback AND $this->shouldTriggerCallback($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations)) {
                    // trigger confirmation callback
                    $f($transaction, $number_of_confirmations, $block_id);

                    // mark as triggered
                    $this->markCallbackTriggered($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations, $block_id);
                }

            }
        }
    }

    protected function shouldTriggerCallback($tx_hash, $destination, $number_of_confirmations) {
        $sth = $this->getDBConnection()->prepare("SELECT COUNT(*) FROM callbacktriggered WHERE tx_hash = ? AND destination = ? AND confirmations = ?");
        $result = $sth->execute([$tx_hash, $destination, $number_of_confirmations]);
        $row = $sth->fetch(PDO::FETCH_NUM);
        return ($row[0] == 0);
    }

    protected function markCallbackTriggered($tx_hash, $destination, $number_of_confirmations, $block_id) {
        $sth = $this->getDBConnection()->prepare("REPLACE INTO callbacktriggered (tx_hash, destination, confirmations, blockId) VALUES (?,?,?,?)");
        $result = $sth->execute([$tx_hash, $destination, $number_of_confirmations, $block_id]);
    }

    ////////////////////////////////////////////////////////////////////////
    // Watch address lookups
       

    protected function isWatchAddress($address) {
        $sth = $this->getDBConnection()->prepare("SELECT COUNT(*) FROM watchaddress WHERE address = ?");
        $result = $sth->execute([$address]);
        $row = $sth->fetch(PDO::FETCH_NUM);
        return ($row[0] > 0);
    }

    protected function buildWatchAddressMap() {
        $sth = $this->getDBConnection()->query("SELECT * FROM watchaddress");
        $map = [];
        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            $map[$row[0]] = true;
        }
        return $map;
    }


    ////////////////////////////////////////////////////////////////////////
    // Carrier Transactions
       

    protected function looksLikeXCPCarrierTransaction($transaction) {
        // 0.000078 = 7800 satoshis
        if ($transaction['quantity'] <= 7800) {
            return true;
        }
        return false;
    }

    protected function savePendingXCPCarrierTransaction($tx_hash, $block_id, $is_mempool) {
        $sth = $this->getDBConnection()->prepare("REPLACE INTO pendingcarriertx (`tx_hash`, `blockId`, `isMempool`, `timestamp`) VALUES (?,?,?,?)");
        $timestamp = time();
        $result = $sth->execute([$tx_hash, $block_id, $is_mempool, $timestamp]);
    }

    protected function isXCPCarrierTransaction($tx_hash) {
        // find a matching XCP transaction
        if ($this->blockchain_tx_directory->findOne(['tx_hash' => $tx_hash, 'isNative' => 0])) {
            return true;
        }

        return false;
    }

    protected function clearMempoolXCPCarrierTransactions() {
        $result = $this->getDBConnection()->exec("DELETE FROM pendingcarriertx WHERE isMempool = 1");
    }

    protected function clearMempoolXCPCarrierTransactionsByBlockID($block_id) {
        $sth = $this->getDBConnection()->prepare("DELETE FROM pendingcarriertx WHERE blockId >= ?");
        $result = $sth->execute([$block_id]);
    }

    protected function handleTimedOutBTCDustTransactions() {
        $old_enough_ts = $this->now() - $this->BTC_DUST_TIMEOUT_TTL;
        $sth = $this->getDBConnection()->prepare("SELECT * FROM pendingcarriertx WHERE timestamp <= ?");
        $result = $sth->execute([$old_enough_ts]);

        $tx_hashes_to_delete = [];
        $tx_hashes_to_process = [];
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $tx_hashes_to_delete[] = $row['tx_hash'];
            if (!$this->isXCPCarrierTransaction($row['tx_hash'])) {
                $tx_hashes_to_process[] = $row['tx_hash'];
            }
        }

        // delete all the tx hashes
        if ($tx_hashes_to_delete) {
            $q_marks = rtrim(str_repeat('?,', count($tx_hashes_to_delete)), ',');
            $result = $this->getDBConnection()->prepare("DELETE FROM pendingcarriertx WHERE tx_hash IN ({$q_marks})")->execute($tx_hashes_to_delete);
        }

        // process all the tx_hashes
        $current_block_id = $this->native_follower->getLastProcessedBlock();
        foreach($tx_hashes_to_process as $tx_hash) {
            $transaction = $this->blockchain_tx_directory->findOne(['tx_hash' => $tx_hash, 'isNative' => 1]);

            // process it
            $number_of_confirmations = $transaction['isMempool'] ? 0 : ($current_block_id - $transaction['blockId'] + 1);
            $was_triggered = $this->callNewTransactionCallbacks($transaction, true, $transaction['isMempool'], $current_block_id, $number_of_confirmations);

            // mark as triggered
            if ($was_triggered) {
                $this->markCallbackTriggered($transaction['tx_hash'], $transaction['destination'], $number_of_confirmations, $current_block_id);
            }
        }
    }

    protected function now() {
        if (isset($this->_now_timestamp)) { return $this->_now_timestamp; }
        return time();
    }

    protected function getDBConnection() {
        return $this->connection_manager->getConnection();
    }

}
