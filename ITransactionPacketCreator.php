<?php

interface ITransactionPacketCreator {
  public static function filterCreatePacketFromRawData($data);
}
