<?php

Interface IClickatellTransactionHandler {
  /**
   * Must return array
   *  uid => unique request id
   *  request_data => array
   */
  public function getNextRequest();

  /**
   * This function will be called when we get a success notification from the network
   */
  public function requestSucceess($uid, $request_data);

  /**
   * This function will be called when we get a failure notification from the network
   */
  public function requestFailure($uid, $request_data);

  /**
   * Put a transaction in the store in a specific state
   */
  public function putTransactionInStore($id, $state, $data);

  /**
   * Get a transaction from the store
   */
  public function getTransactionFromStore($id);


  /**
   * Count the number of transactions in a specific state
   */
  public function countTransactionsInState($state);

  /**
   * Set transaction state
   */
  public function setTransactionState($id, $state);

  /**
   * Set transaction state
   */
  public function getTransactionState($id);

  /**
   * Get the number of times we have transmitted the transaction request
   */
  public function incrementTransactionTransmissionCount($id);

  /**
   * Return the number if times we have transmitted the transaction
   */
  public function getTransactionTransmissionCount($id);
}
