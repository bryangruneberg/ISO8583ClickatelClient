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

}
