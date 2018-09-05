# php-websocket-client

Либа для работы с MQTT через WebSocket

Пока используем так:

> if (!$streamObj->open('ws://78.29.0.240:8082/mqtt', $params)) {
>    die;
> }
>
> while ($ping = $streamObj->checkConnection()) {
>    $streamObj->sendData("\xff", Websocket::TYPE_BINARY);
>    $data = $streamObj->getData();
>    sleep(5);
> }
> 
> $streamObj->disconnect();
