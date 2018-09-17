# php-websocket-client

Либа для работы с MQTT через WebSocket

# MQTT клиент
Пример работы:
```php
$client = new MqttClient();
$client->setClientId('test_area');
$client->sender = function ($client) use ($topics, $data) {
    sleep(1);
    $res = $client->createSubscribePacket($topics);
    return $res;
};

$client->open($url, []);
$client->run();
```

## Функции

* `setClientId(string $clientId)` - Задает ClientId
* `setDebug(bool $debugMode)` - Задает режим отладки
* `open(string $url, array $params)` - Открывает поток по адресу $url с параметрами $params
* `run()` - запускает петлю.

* `createPublishPacket($topic, $content, $qos = 0, $dup = false, $retain = false)` - создает пакет для публикации
* `createSubscribePacket(array $topics)` - создает пакет для подписки на `$topics`
* `createUnsubscribePacket(array $topics)` - создает пакет для отписки от `$topics`

### Функция open()
Открывает соединение с MQTT сервером

Параметры:

**$url** - задает URL для подключения. 

Протокол можно указать "ws://|wss://" - подключение будет через WebSocket, или "tcp:// | udp:// " - для прямого подключения

**$params** - ассоциативный массив с параметрами подключения. Поддерживает:
* `clean` - Если `true` - сервер будет сбрасывать сессию после разрыва подключения, если false - сервер сохраняет сообщения QoS 1 и 2 для передачи после восстановления сессии
* `will` - ассоциативный массив с настройками для `will`. Поддерживает ключи: `retain`, `qos`, `topic`, `message`
* `username` - Имя пользователя,
* `password` => Пароль
 
 
### Функции createSubscribePacket() и createUnsubscribePacket()

Функции для генерации пакетов на подписку и отписку от топиков.

Топики передаются в массиве `$topics`
Каждый элемент массива - массив с настройками топика:
```php
[
    [
        'filter' => "device/08:16:29:3C:FC:1B/open",
        "qos" => 1
    ],
]
```

### Функция createPublishPacket()
Создает пакет для публикации
Параметры:
**$topic** - Топик, в который публикуется сообщение
**$content** - текст сообщения
**$qos** - Уровень QoS. Уровень 2 пока не поддерживается
**$dup** - признак повторной отправки пакета
**$retain** - Признак хранения сообщения для новых подписчиков

## События
Для подписания на событие - вешаем обработчик. Все обработчики принимают параметр $msg - пакет, который обработался

* `onConnected` - при подключении
* `onDisconnect` - при отключении
* `onPingResp` - при получении ответа при пинге
* `onSubscribe` - когда подписался
* `onUnsubscribe` - когда отписался
* `onPublish` - после отправки сообщения и при получении по подписке

## Обработчик

Вешаем на `sender` функцию. Вызывается на каждую итерацию. 
Возвращать должна или false, если ничего не делает, или сформированный пакет, который будет отправлен на сервер.

Не забывать ставить sleep() или другую задержку в функцию. Иначе слишком часто вызывается.
