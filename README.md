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

#MQTT клиент
> $client = new MqttClient();
> $client->setClientId('test_area');
> $client->onPublish = function ($msg) {
    var_dump($msg);
};
$client->onDisconnect = function ($msg) {
    var_dump($msg);
};
$client->onPingResp = function ($msg) {
    echo 'Ping Ok' . PHP_EOL;
};
$client->onConnected = function ($msg) {
    echo "Connected to Broker" . PHP_EOL;
};
$client->onSubscribe = function ($msg) {
    echo "Подписался" . PHP_EOL;
};
$client->onUnsubscribe = function ($msg) {
    echo "Отписался" . PHP_EOL;
};
> $client->sender = function ($client) use ($topics, $data) {
    /** @var $client MqttClient */
    $res = $client->createPublishPacket("device/08:16:29:3C:FC:1B/open", json_encode($data), 1);
    return $res;
};

> $client->open($url, $params);
> $client->run();

##События
Для подписания на событие - вешаем обработчик. Все обработчики принимают параметр $msg - пакет, который обработался

* `onConnected` - при подключении
* `onDisconnect` - при отключении
* `onPingResp` - при получении ответа при пинге
* `onSubscribe` - когда подписался
* `onUnsubscribe` - когда отписался
* `onPublish` - после отправки сообщения и при получении по подписке

##Обработчик

Вешаем на `sender` функцию. Вызывается на каждую итерацию. 
Возвращать должна или false, если ничего не делает, или сформированный пакет, который будет отправлен на сервер.

Не забывать ставить sleep() или другую задержку в функцию. Иначе излишне много крутистя 

###Функции для формирования пакетов:

* **createPublishPacket($topic, $content, $qos = 0, $dup = false, $retain = false)** - создает пакет для отправки в `$topic` с содержимым `$content`

* **createSubscribePacket($topic)** - подписка на `$topic`
* **createUnsubscribePacket($topic)** - отписаться от `$topic`
