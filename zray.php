<?php
/*********************************
	Zend Framework 1 Z-Ray Extension
	Version: 1.00
**********************************/

namespace ZF1;

use Traversable,
    Closure,
    ReflectionProperty;

class ZF1 {
    
    private $isExceptionSaved = false;

	public function storeDispatcherExit($context, &$storage) {
	    $Zend_Controller_Dispatcher_Standard = $context["this"];
	    $request = $context["functionArgs"][0];
	    
	    $action = $Zend_Controller_Dispatcher_Standard->getActionMethod($request);
	    $className = $this->getControllerName($Zend_Controller_Dispatcher_Standard, $request);
	    $storage['request'][] = array (  'action' => $action,
	                                     'controller' => $className,
	                                     'moduleClassName' => $this->getModuleClassName($Zend_Controller_Dispatcher_Standard, $className));
	}
	
	public function storeFrontDispatchExit($context, &$storage) {
		$Zend_Controller_Front = $context["this"];
		if (method_exists($Zend_Controller_Front, 'getPlugins')) {
			$plugins = $Zend_Controller_Front->getPlugins();
			 
			foreach ($plugins as $plugin) {
			  $storage['plugin'][get_class($plugin)] = $this->makeArraySerializable($plugin);
			}
		}
	}
	
    public function storeViewExit($context, &$storage) {
    	$storage['view'][] = $context["functionArgs"];
    }
    
    public function storeViewHelperExit($context, &$storage) {
    	
    	$name = $context["functionArgs"][0];
    	$args = $context["functionArgs"][1];
    	
    	$Zend_View_Abstract = $context["this"];
    	$helper = $Zend_View_Abstract->getHelper($name);
    	

    	$reflect = new \ReflectionClass($helper);
    	
    	$properties = array();
    	foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $prop) {
    	    $prop->setAccessible(true);
    	    $value = $prop->getValue($helper);
    	    if (is_object($value)) {
    	        $properties[$prop->getName()] = get_class($value);
    	    } else {
    	        $properties[$prop->getName()] = $value;
    	    }
    	}
    	
    	$helpersArgs = array();
    	foreach ($args as $arg) {
    	    if (is_object($arg)) {
        	    $reflect = new \ReflectionClass($arg);
        	     
        	    $properties = array();
        	    foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $prop) {
        	        $prop->setAccessible(true);
        	        $value = $prop->getValue($arg);
        	        if (is_object($value)) {
        	            $properties[$prop->getName()] = get_class($value);
        	        } else {
        	            $properties[$prop->getName()] = $value;
        	        }
        	    }
        	    $helpersArgs[get_class($arg)] = $properties;
    	    } else {
    	        $helpersArgs[] = $arg;
    	    }
    	}
    	
    	$storage['viewHelpers'][$name] = array(    'args'          => $helpersArgs,
    	                                           'helperObject'  => $properties);
    }
    
    public function storeHandleErrorExit($context, &$storage) {
        
        $Zend_Controller_Plugin_ErrorHandler = $context["this"];
        $this->getException($Zend_Controller_Plugin_ErrorHandler, $storage);
    }

    
    public function storeRouterRewriteRequestExit($context, &$storage) {
       if(! $context["exceptionThrown"]) {
           $storage['requestObject'][] = $context['returnValue'];
       }
    }
    
	////////////// PRIVATES ///////////////////
	
    private function getException($Zend_Controller_Plugin_ErrorHandler, &$storage) {
        $response = $Zend_Controller_Plugin_ErrorHandler->getResponse();

        $reflection = new \ReflectionProperty('Zend_Controller_Plugin_ErrorHandler', '_isInsideErrorHandlerLoop');
        $reflection->setAccessible(true);
        $_isInsideErrorHandlerLoop = $reflection->getValue($Zend_Controller_Plugin_ErrorHandler);
        
        // check for an exception AND allow the error handler controller the option to forward
        if ($response->isException() && !$this->isExceptionSaved) {
            // Get exception information
            $error            = new \ArrayObject(array(), \ArrayObject::ARRAY_AS_PROPS);
            $exceptions       = $response->getException();
            $exception        = $exceptions[0];
            $exceptionType    = get_class($exception);
            $error->exception = $exception;
            switch ($exceptionType) {
                case 'Zend_Controller_Router_Exception':
                    if (404 == $exception->getCode()) {
                        $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ROUTE;
                    } else {
                        $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_OTHER;
                    }
                    break;
                case 'Zend_Controller_Dispatcher_Exception':
                    $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_CONTROLLER;
                    break;
                case 'Zend_Controller_Action_Exception':
                    if (404 == $exception->getCode()) {
                        $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ACTION;
                    } else {
                        $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_OTHER;
                    }
                    break;
                default:
                    $error->type = $Zend_Controller_Plugin_ErrorHandler::EXCEPTION_OTHER;
                    break;
               
            }
            
            $this->isExceptionSaved = true;
            $storage['ErrorHandler'][] = array (    'exceptionType' => $exceptionType,
                                                    'error' => $error,
                                                    'exception' => $exception);
        }
    }
    
    private function getControllerName($Zend_Controller_Dispatcher_Standard, $request) {
        /**
         * Get controller class
         */
        if (!$Zend_Controller_Dispatcher_Standard->isDispatchable($request)) {
        	$controller = $request->getControllerName();
        	if (!$Zend_Controller_Dispatcher_Standard->getParam('useDefaultControllerAlways') && !empty($controller)) {
        		return "";
        	}
        
        	$className = $Zend_Controller_Dispatcher_Standard->getDefaultControllerClass($request);
        } else {
        	$className = $Zend_Controller_Dispatcher_Standard->getControllerClass($request);
        	if (!$className) {
        		$className = $Zend_Controller_Dispatcher_Standard->getDefaultControllerClass($request);
        	}
        }
        return $className;
    }
    
    private function getModuleClassName($Zend_Controller_Dispatcher_Standard, $className) {
        $moduleClassName = $className;
       
        
        $reflection = new \ReflectionProperty('Zend_Controller_Dispatcher_Standard', '_curModule');
        $reflection->setAccessible(true);
        $_curModule = $reflection->getValue($Zend_Controller_Dispatcher_Standard);
     
        
        $reflection = new \ReflectionProperty('Zend_Controller_Dispatcher_Standard', '_defaultModule');
        $reflection->setAccessible(true);
        $_defaultModule = $reflection->getValue($Zend_Controller_Dispatcher_Standard);
        
        if (($_defaultModule != $_curModule)
        		|| $Zend_Controller_Dispatcher_Standard->getParam('prefixDefaultModule'))
        {
        	$moduleClassName = $Zend_Controller_Dispatcher_Standard->formatClassName($_curModule, $className);
        }
        return $moduleClassName;
    }

        /**
     * Replaces the un-serializable items in an array with stubs
     *
     * @param array|\Traversable $data
     *
     * @return array
     */
    private function makeArraySerializable($data) {
        $serializable = array();
        try {
            foreach (self::iteratorToArray($data) as $key => $value) {
                if ($value instanceof Traversable || is_array($value)) {
                    $serializable[$key] = $this->makeArraySerializable($value);

                    continue;
                }

                if ($value instanceof Closure) {
                    $serializable[$key] = new ClosureStub();
                    continue;
                }

                $serializable[$key] = $value;
            }
        } catch (\InvalidArgumentException $e) {
            return $serializable;
        }

        return $serializable;
    }

    public static function iteratorToArray($iterator, $recursive = true) {
        if (!is_array($iterator) && !$iterator instanceof Traversable) {
            throw new \InvalidArgumentException(__METHOD__ . ' expects an array or Traversable object');
        }

        if (!$recursive) {
            if (is_array($iterator)) {
                return $iterator;
            }

            return iterator_to_array($iterator);
        }

        if (method_exists($iterator, 'toArray')) {
            return $iterator->toArray();
        }

        $array = array();
        foreach ($iterator as $key => $value) {
            if (is_scalar($value)) {
                $array [$key] = $value;
                continue;
            }

            if ($value instanceof Traversable) {
                $array [$key] = static::iteratorToArray($value, $recursive);
                continue;
            }

            if (is_array($value)) {
                $array [$key] = static::iteratorToArray($value, $recursive);
                continue;
            }

            $array [$key] = $value;
        }

        return $array;
    }
}

/**
 * Empty class that represents an {@see \Closure} object
 */
class ClosureStub {
}

// Allocate ZRayExtension for namespace "zf1"
$zre = new \ZRayExtension("zf1");
$zf1Storage = new ZF1();

$zre->setMetadata(array(
	'logo' => __DIR__ . DIRECTORY_SEPARATOR . 'logo.png',
));

$zre->setEnabledAfter('Zend_Controller_Front::dispatch');

$zre->traceFunction("Zend_Controller_Dispatcher_Standard::dispatch",  function(){}, array($zf1Storage, 'storeDispatcherExit'));
$zre->traceFunction("Zend_Controller_Front::dispatch", function(){}, array($zf1Storage, 'storeFrontDispatchExit'));
$zre->traceFunction("Zend_View::_run",  function(){}, array($zf1Storage, 'storeViewExit'));
$zre->traceFunction("Zend_View_Abstract::__call", function(){}, array($zf1Storage, 'storeViewHelperExit'));
$zre->traceFunction("Zend_Controller_Plugin_ErrorHandler::_handleError", function(){}, array($zf1Storage, 'storeHandleErrorExit'));
$zre->traceFunction("Zend_Controller_Router_Rewrite::route", function(){} , array($zf1Storage, 'storeRouterRewriteRequestExit'));
