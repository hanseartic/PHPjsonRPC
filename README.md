PHPjsonRPC
===========

Serves your PHP-scripts via an RPC-style server and makes their methods accessible via JSONrpc.

Requirements
------------
PHP 5 (> 5.3.0) is needed to interpret PHPjsonRPC, as it makes use of [namespaces](http://php.net/manual/en/language.namespaces.php "PHP namespaces").

Usage
-----
    <?php
    include('RPCServer.php');
    include('MyObj.php');
    $rpcServer = new RPCServer;

    # You can bind RPCServer to already configured instance of an object...
    $myObj = new MyObj;
    $myObj->property1 = $value1;
    $rpcServer->Bind($myObj);

    # or set object's properties while binding...
    $rpcServer->Bind(array('type' => 'myObj', 'property1' => $value1, 'property2' => $value2,));

    # or bind to an object by classname
    $rpcServer->Bind(array('MyObj');

    # To start processing requests just listen
    ob_start();
    $rpcSuccess = $rpcServer->Listen();
    $rpcResult = ob_get_clean();
    if (FALSE === $rpcSuccess) {
        // handle error (e.g. print message)
        print_r($rpcServer->GetErrorMessage());
    } else exit ($rpcResult);
    
Formatting of requests must follow the [specification](http://www.jsonrpc.org/specification#request_object "RequestObject in jsonrpc-spec").
