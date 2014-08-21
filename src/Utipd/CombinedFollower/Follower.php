<?php 

namespace Utipd\CombinedFollower;

use PDO;
use Utipd\CombinedFollower\Models\Directory\BlockchainTransactionDirectory;
use Utipd\CombinedFollower\Models\Directory\WatchAddressDirectory;

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

    function __construct($native_follower, $xcpd_follower, PDO $db_connection) {
        $this->native_follower = $native_follower;
        $this->xcpd_follower = $xcpd_follower;
        $this->db_connection = $db_connection;

        // init directories
        $this->blockchain_tx_directory = new BlockchainTransactionDirectory($db_connection);

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
        $result = $this->db_connection->prepare("REPLACE INTO watchaddress VALUES (?)")->execute([$bitcoin_address]);
    }

    public function removeAddressToWatch($bitcoin_address) {
        $result = $this->db_connection->prepare("DELETE FROM watchaddress WHERE address = ?")->execute([$bitcoin_address]);
    }

    public function clearAllAddressesToWatch() {
        $result = $this->db_connection->exec("TRUNCATE watchaddress");
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
            } else {
                $current_block_id = $block_id;
            }

            if ($this->isWatchAddress($send_data['destination'])) {
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

            // now check every confirmed send withing max_confirmations blocks and call the callback
            $this->invokeConfirmedTransactionCallbacks($block_id, $is_native=true);
        });

        $this->native_follower->handleNewTransaction(function($transaction, $block_id, $is_mempool) {
            // a new transaction
            // txid: cc91db2f18b908903cb7c7a4474695016e12afd816f66a209e80b7511b29bba9
            // outputs:
            //     - amount: 100000
            //       address: "1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            if ($is_mempool) {
                $current_block_id = $this->native_follower->getLastProcessedBlock();
            } else {
                $current_block_id = $block_id;
            }


            // find the accounts that we care about watching
            $all_watch_addresses_map = $this->buildWatchAddressMap();

            foreach ($transaction['outputs'] as $output) {
                if (!$output['address']) { continue; }

                $destination_address = $output['address'];

                // do we care about this destination address?
                if (isset($all_watch_addresses_map[$destination_address])) {
                    $btc_send_data = [];
                    $btc_send_data['tx_index']    = $transaction['txid'];
                    $btc_send_data['block_index'] = $block_id;
                    $btc_send_data['source']      = ''; // not tracked
                    $btc_send_data['destination'] = $destination_address;
                    $btc_send_data['asset']       = 'BTC';
                    $btc_send_data['quantity']    = $output['amount']; // already in satoshis
                    $btc_send_data['status']      = 'valid';
                    $btc_send_data['tx_hash']     = $transaction['txid'];

                    $transaction = $this->createNewTransaction($btc_send_data, $is_native=true, $is_mempool, $current_block_id);
                    $this->invokeNewTransactionCallbacks($transaction, $is_native=true, $is_mempool, $current_block_id);
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

        });
    }


    protected function clearAllMempoolTransactions($is_native) {
        $this->blockchain_tx_directory->deleteRaw("DELETE FROM {$this->blockchain_tx_directory->getTableName()} WHERE isMempool = ? AND isNative = ?", [1, intval($is_native)]);
    }

    ////////////////////////////////////////////////////////////////////////
    // callbacks
    
    protected function invokeNewTransactionCallbacks($transaction, $is_native, $is_mempool, $current_block_id) {
        if ($is_mempool) {
            // mempool
            if (isset($this->mempool_tx_callback_fn)) {
                $f = $this->mempool_tx_callback_fn;
                $f($transaction, $current_block_id);
            }
        } else {
            // confirmed
            if (isset($this->confirmed_tx_callback_fn)) {

                // always send first confirmation
                $f = $this->confirmed_tx_callback_fn;
                $number_of_confirmations = 1;
                $f($transaction, $number_of_confirmations, $current_block_id);

                // mark as triggered
                $this->markConfirmationTriggered($transaction['tx_hash'], $number_of_confirmations, $current_block_id);
            }
        }
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
            $confirmations_in_db = $block_id - $transaction['blockId'] + 1;
            $max_number_of_confirmations = min($confirmations_in_db, $this->max_confirmations_for_confirmed_tx);

            // trigger every confirmation up to $this->max_confirmations_for_confirmed_tx
            for ($i=0; $i < $max_number_of_confirmations; $i++) { 
                $number_of_confirmations = $i + 1;
                if ($this->shouldTriggerConfirmation($transaction['tx_hash'], $number_of_confirmations)) {
                    // trigger confirmation callback
                    $f($transaction, $number_of_confirmations, $block_id);

                    // mark as triggered
                    $this->markConfirmationTriggered($transaction['tx_hash'], $number_of_confirmations, $block_id);
                }
            }

        }
    }

    protected function shouldTriggerConfirmation($tx_hash, $number_of_confirmations) {
        $sth = $this->db_connection->prepare("SELECT COUNT(*) FROM confirmationtriggered WHERE tx_hash = ? AND confirmations = ?");
        $result = $sth->execute([$tx_hash, $number_of_confirmations]);
        $row = $sth->fetch(PDO::FETCH_NUM);
        return ($row[0] == 0);
    }

    protected function markConfirmationTriggered($tx_hash, $number_of_confirmations, $block_id) {
        $sth = $this->db_connection->prepare("REPLACE INTO confirmationtriggered (tx_hash, confirmations, blockId) VALUES (?,?,?)");
        $result = $sth->execute([$tx_hash, $number_of_confirmations, $block_id]);
    }

    ////////////////////////////////////////////////////////////////////////
    // Watch address lookups
       

    protected function isWatchAddress($address) {
        $sth = $this->db_connection->prepare("SELECT COUNT(*) FROM watchaddress WHERE address = ?");
        $result = $sth->execute([$address]);
        $row = $sth->fetch(PDO::FETCH_NUM);
        return ($row[0] > 0);
    }

    protected function buildWatchAddressMap() {
        $sth = $this->db_connection->query("SELECT * FROM watchaddress");
        $map = [];
        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            $map[$row[0]] = true;
        }
        return $map;
    }

}
