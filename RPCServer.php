<?php
/*
 * RPCServer.php
 * Created: 12.02.13 09:31
 * Copyright 2013 Paul Schütte
 *
 * This file is part of php-jsonRPC (https://github.com/hanseartic/PHPjsonRPC)
 *
 * php-jsonRPC is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * php-jsonRPC is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with php-jsonRPC. If not, see http://www.gnu.org/licenses/.
 */

/**
 * Acts as an RPC-Server to serve existing methods over an RPC-style interface.
 *
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */
namespace PHPjsonRPC;
class RPCServer {

	private $forbiddenMethods = array();
	private $errorMessage = "";
	private $handleErrorMethod = null;
	private $handles;
	private $request = "";

	public function __construct() {
		$this->handles = array();
		$this->request = file_get_contents("php://input");
	}

	/**
	 * Adds a method-name to the list of blocked methods. Blocked methods are not executed when requested via RPC.
	 *
	 * @param string $methodName The name of a method that should not be exposed over RPC
	 *
	 * @return array List of blocked methods
	 */
	public function BlockMethod($methodName) {
		$this->forbiddenMethods[$methodName] = $methodName;
		return $this->forbiddenMethods;
	}

	/**
	 * Removes a method-name from the list of blocked methods. Blocked methods are not executed when requested via RPC.
	 *
	 * @param string $methodName The name of a method that should not be block any longer
	 *
	 * @return array List of blocked methods
	 */
	public function UnblockMethod($methodName) {
		if (array_key_exists($methodName, $this->forbiddenMethods)) {
			unset ($this->forbiddenMethods[$methodName]);
		}
		return $this->forbiddenMethods;
	}

	/**
	 * Adds a binding for an object to be handled by \RPCServer.
	 * The object definition can be the actual object, an array containing the type and optional properties or
	 * just a string defining the type.
	 *
	 * <code>
	 * <?php
	 * include('RPCServer.php');
	 * include('MyObj.php');
	 * $rpcServer = new RPCServer;
	 * $myObj = new MyObj;
	 * $myObj->property1 = $value1;
	 * $rpcServer->Bind($myObj);
	 * $rpcServer->Bind(array('type' => 'myObj', 'property1' => $value1, 'property2' => $value2,));
	 * $rpcServer->Bind(array('MyObj');
	 * ?>
	 * </code>
	 *
	 * @param object|string|array $object defines an to be handled by RPCServer.
	 *
	 * @return bool Returns true, if a binding could be established, false otherwise.
	 */
	public function Bind($object) {
		$obj = RPCServer::getHandleObject($object);
		if (null !== $obj) {
			$this->handles[get_class($obj)] = $obj;
			return true;
		}
		return false;
	}

	/**
	 * Replaces the current bindings for objects to be handled by RPCServer with @param $objects.
	 *
	 * @param (object|string|array)[]|null $objects @see Bind() for description of the possible parameters. If the parameter is omitted, this method acts just as getter. If parameter is null or an empty array, all bindings will be removed.
	 *
	 * @return object[] objects handled by RPCServer
	 * @throws \InvalidArgumentException
	 */
	public function Handles(array $objects = null) {
		if (func_num_args() === 1) {
			if (is_array($objects)) {
				$this->handles = array();
				foreach ($objects as $object) {
					$this->Bind($object);
				}
			} elseif (null === $objects) {
				$this->handles = array();
			} else {
				throw new \InvalidArgumentException("The parameter must be an array.");
			}
		}
		return $this->handles;
	}

	/**
	 * @return string the error-message stored if Listen() encountered a malformed request.
	 */
	public function GetErrorMessage() {
		return $this->errorMessage;
	}

	/**
	 * Handles errors that occured during Listen().
	 * This method actually sends headers if the request handled by Listen() was not well-formed.
	 */
	public function HandleError() {
		if (null === $this->handleErrorMethod) return;
		call_user_func($this->handleErrorMethod);
	}

	/**
	 * Tries to hand off the request to one of the objects bound to RPCServer.
	 *
	 * @param bool $autoHandleErrors defines if headers are sent automatically on error. Default is false.
	 * @return string|bool returns true when the request was successfully handled, false otherwise.
	 * @throws \BadMethodCallException|\BadFunctionCallException
	 */
	public function Listen($autoHandleErrors = false) {
		$this->errorMessage = "";
		$this->handleErrorMethod = null;
		if (false === $this->HasInput()) return false;

		if (("POST" != $_SERVER['REQUEST_METHOD']) ||
			empty($_SERVER['CONTENT_TYPE']) ||
			(0 !== stripos($_SERVER['CONTENT_TYPE'], 'application/json'))) {
			$result = array(
				'code' => -32400,
				'message' => "",
				'data' => array(
					'allowed' => array(),
					'received' => array(),
					'request' => $this->request,
				),
			);
			if (empty($_SERVER['CONTENT_TYPE']) ||
				(0 !== stripos($_SERVER['CONTENT_TYPE'], 'application/json'))) {
				$this->handleErrorMethod = function() {
					header('HTTP/1.0 400 Bad Request', false, 400);
				};
				$result['message'] = "Invalid content type.";
				$result['data']['received']['CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'];
				$result['data']['allowed']['CONTENT_TYPE'] = "application/json";
			} elseif ("POST" != $_SERVER['REQUEST_METHOD']) {
				$this->handleErrorMethod = function() {
					header('HTTP/1.0 405 Method not allowed', false, 405);
					header('Access-Control-Allow-Methods: POST');
				};
				$result['message'] = "Method [{$_SERVER['REQUEST_METHOD']}] not allowed.";
				$result['received']['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'];
				$result['allowed']['REQUEST_METHOD'] = "POST";
			}
			$this->errorMessage = json_encode(array(
				'id' => null,
				'jsonrpc' => "2.0",
				'error' => $result,
			));
			if (true === $autoHandleErrors) {
				$this->HandleError();
				return true;
			}
			return false;
		}

		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Headers: Content-Type');
		header('Access-Control-Allow-Methods: POST');

		$request = json_decode($this->request, true);
		$requests = (is_array($request) && (! array_key_exists("method", $request)))
			? $request
			: array($request);
		$responses = array();
		foreach ($requests as $request) {
			$response = $this->handleRequest($request);
			if (null !== $response)
				$responses[] = $response;
		}
		$response = (1 >= count($responses))
			? current($responses)
			: $responses;
		if ($response && (count($response) > 0)) {
			header('Content-type: application/json; charset=UTF-8', true);
			echo json_encode($response);
		}
		return true;
	}

	private function handleRequest($request) {
		$response = array(
			'id' => null,
			'jsonrpc' => "2.0",
		);
		try {
			if (empty($request['method'])) {
				throw new \HttpQueryStringException();
			}
			$methodName = $request['method'];
			if (array_key_exists($request['method'], $this->forbiddenMethods)) {
				throw new \BadFunctionCallException("The requested function does not exist.");
			}
			$handles = $this->Handles();
			$handle = current($handles);
			while (!method_exists($handle, $methodName)) {
				$handle = next($handles);
				if (false === $handle) throw new \BadMethodCallException("Requested method is not defined.");
			}
            if (! isset($request['params']))
                $request['params'] = array();
			$methodResult = @call_user_func_array(array($handle, $methodName), $request['params']);
			$response['id'] = $request['id'];
			if (false !== $methodResult) {
				$response['result'] = $methodResult;
			} else {
				$response['error']['code'] = -32601;
				$response['error']['message'] = "Unknown method or invalid parameters.";
				$response['error']['data']['request'] = $this->request;
			}
		} catch (\Exception $exception) {
			$response['id'] = $request['id'];
			$response['error']['code'] = -32602;
			if ("BadFunctionCallException" == get_class($exception) ||
				"BadMethodCallException" == get_class($exception)) {
				$response['error']['code'] = -32601;
				$response['error']['message'] = $exception->getMessage();
			}
			if ("HttpQueryStringException" == get_class($exception)) {
				$response['error']['code'] = -32600;
				$response['error']['message'] = "Invalid Request";
			}
			$response['error']['data']['request'] = $this->request;
		}
		if (!empty($request['id'])) {
			return $response;
		}
		return null;
	}

	/**
	 * @return bool true if php://input contained data to be processed, false otherwise
	 */
	public function HasInput() {
		return (0 < mb_strlen($this->request));
	}

	private static function getHandleObject($object) {
		$result = null;
		if (is_object($object)) {
			$result = $object;
		} elseif (is_string($object)) {
			try {
				$result = new $object;
			} catch (\Exception $ex) {}
		} elseif (is_array($object) && array_key_exists("type", $object)) {
			$obj = new $object['type'];
			unset ($object['type']);
			try {
				foreach ($object as $property => $value) {
					$obj->$property = $value;
				}
			} catch (\Exception $ex) {}
			$result = $obj;
		}
		return $result;
	}
}
