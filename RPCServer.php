<?php
/*
 * RPCServer.php
 * Created: 12.02.13 09:31
 * Copyright 2013 Paul Schütte
 *
 * This file is part of php-jsonRPC.
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

	private $handles;
	private $request = "";
	private $errorMessage = "";
	private $handleErrorMethod = null;

	public function __construct() {
		$this->handles = array();
		$this->request = file_get_contents("php://input");
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
	 * @throws InvalidArgumentException
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
				throw new InvalidArgumentException("The parameter must be an array.");
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
	 * @throws BadMethodCallException
	 */
	public function Listen($autoHandleErrors = false) {
		$this->errorMessage = "";
		$this->handleErrorMethod = null;
		if (false === $this->HasInput()) return false;

		if (("POST" != $_SERVER['REQUEST_METHOD']) ||
			empty($_SERVER['CONTENT_TYPE']) ||
			(0 !== stripos($_SERVER['CONTENT_TYPE'], 'application/json'))) {
			$result = array(
				'code' => 1,
				'message' => "",
				'allowed' => array(),
				'received' => array(),
			);
			if (empty($_SERVER['CONTENT_TYPE']) ||
				(0 !== stripos($_SERVER['CONTENT_TYPE'], 'application/json'))) {
				$this->handleErrorMethod = function() {
					header('HTTP/1.0 400 Bad Request', false, 400);
				};
				$result['message'] = "Invalid content type.";
				$result['received']['CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'];
				$result['allowed']['CONTENT_TYPE'] = "application/json";
			} elseif ("POST" != $_SERVER['REQUEST_METHOD']) {
				$this->handleErrorMethod = function() {
					header('HTTP/1.0 405 Method not allowed', false, 405);
					header('Access-Control-Allow-Methods: POST');
				};
				$result['message'] = "Method [{$_SERVER['REQUEST_METHOD']}] not allowed.";
				$result['received']['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'];
				$result['allowed']['REQUEST_METHOD'] = "POST";
			}
			$this->errorMessage = json_encode(array('status' => $result));
			if (true === $autoHandleErrors) {
				$this->HandleError();
				return true;
			}
			return false;
		}

		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Headers: Content-Type');
		header('Access-Control-Allow-Methods: POST');

		$requestBody = json_decode($this->request, true);
		$response = array(
			'id' => -1,
			'status' => array(
				'code' => 0,
				'message' => "",
			),
		);
		try {
			$methodName = $requestBody['method'];
			$handles = $this->Handles();
			$handle = current($handles);
			while (!method_exists($handle, $methodName)) {
				$handle = next($handles);
				if (false === $handle) throw new BadMethodCallException("Requested method is not defined.");
			}
			$methodResult = @call_user_func_array(array($handle, $methodName), $requestBody['params']);
			$response['id'] = $requestBody['id'];
			$response[$requestBody['method']] = $methodResult;
			if (false !== $methodResult) {
				$response['status']['message'] = "Operation successful.";
			} else {
				$response['status']['code'] = 2;
				$response['status']['message'] = "Unknown method or invalid parameters.";
			}
		} catch (Exception $exception) {
			$response['id'] = $requestBody['id'];
			$response['status']['code'] = 1;
			$response['status']['message'] = $exception->getMessage();
			$response[$requestBody['method']] = null;
		}
		if (!empty($requestBody['id'])) {
			header('Content-type: application/json; charset=UTF-8', true);
			echo json_encode($response);
		}
		return true;
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
			} catch (Exception $ex) {}
		} elseif (is_array($object) && array_key_exists("type", $object)) {
			$obj = new $object['type'];
			unset ($object['type']);
			try {
				foreach ($object as $property => $value) {
					$obj->$property = $value;
				}
			} catch (Exception $ex) {}
			$result = $obj;
		}
		return $result;
	}
}
