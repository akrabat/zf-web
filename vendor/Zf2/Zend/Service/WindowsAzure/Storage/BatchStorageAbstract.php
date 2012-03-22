<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend\Service\WindowsAzure
 * @subpackage Storage
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

namespace Zend\Service\WindowsAzure\Storage;

use Zend\Service\WindowsAzure\Storage,
    Zend\Service\WindowsAzure\Storage\Batch;

/**
 * @category   Zend
 * @package    Zend\Service\WindowsAzure
 * @subpackage Storage
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class BatchStorageAbstract extends Storage
{	
    /**
     * Current batch
     * 
     * @var Zend\Service\WindowsAzure\Storage\Batch
     */
    protected $_currentBatch = null;
    
    /**
     * Set current batch
     * 
     * @param Zend\Service\WindowsAzure\Storage\Batch $batch Current batch
     * @throws Zend\Service\WindowsAzure\Exception
     */
    public function setCurrentBatch(Batch $batch = null)
    {
        if (!is_null($batch) && $this->isInBatch()) {
            throw new Exception\InvalidArgumentException('Only one batch can be active at a time.');
        }
        $this->_currentBatch = $batch;
    }
    
    /**
     * Get current batch
     * 
     * @return Zend\Service\WindowsAzure\Storage\Batch
     */
    public function getCurrentBatch()
    {
        return $this->_currentBatch;
    }
    
    /**
     * Is there a current batch?
     * 
     * @return boolean
     */
    public function isInBatch()
    {
        return !is_null($this->_currentBatch);
    }
    
    /**
     * Starts a new batch operation set
     * 
     * @return Zend\Service\WindowsAzure\Storage\Batch
     * @throws Zend\Service\WindowsAzure\Exception
     */
    public function startBatch()
    {
        return new Batch($this, $this->getBaseUrl());
    }
	
	/**
	 * Perform batch using Zend\Http\Client channel, combining all batch operations into one request
	 *
	 * @param array $operations Operations in batch
	 * @param boolean $forTableStorage Is the request for table storage?
	 * @param boolean $isSingleSelect Is the request a single select statement?
	 * @param string $resourceType Resource type
	 * @param string $requiredPermission Required permission
	 * @return Zend\Http\Response
	 */
	public function performBatch($operations = array(), $forTableStorage = false, $isSingleSelect = false, $resourceType = Zend\Service\WindowsAzure\Storage::RESOURCE_UNKNOWN, $requiredPermission = Zend\Service\WindowsAzure\Credentials\CredentialsAbstract::PERMISSION_READ)
	{
	    // Generate boundaries
	    $batchBoundary = 'batch_' . md5(time() . microtime());
	    $changesetBoundary = 'changeset_' . md5(time() . microtime());
	    
	    // Set headers
	    $headers = array();
	    
		// Add version header
		$headers['x-ms-version'] = $this->_apiVersion;
		
		// Add dataservice headers
		$headers['DataServiceVersion'] = '1.0;NetFx';
		$headers['MaxDataServiceVersion'] = '1.0;NetFx';
		
		// Add content-type header
		$headers['Content-Type'] = 'multipart/mixed; boundary=' . $batchBoundary;

		// Set path and query string
		$path           = '/$batch';
		$queryString    = '';
		
		// Set verb
		$httpVerb = Zend\Http\Request::METHOD_POST;
		
		// Generate raw data
    	$rawData = '';
    		
		// Single select?
		if ($isSingleSelect) {
		    $operation = $operations[0];
		    $rawData .= '--' . $batchBoundary . "\n";
            $rawData .= 'Content-Type: application/http' . "\n";
            $rawData .= 'Content-Transfer-Encoding: binary' . "\n\n";
            $rawData .= $operation; 
            $rawData .= '--' . $batchBoundary . '--';
		} else {
    		$rawData .= '--' . $batchBoundary . "\n";
    		$rawData .= 'Content-Type: multipart/mixed; boundary=' . $changesetBoundary . "\n\n";
    		
        		// Add operations
        		foreach ($operations as $operation)
        		{
                    $rawData .= '--' . $changesetBoundary . "\n";
                	$rawData .= 'Content-Type: application/http' . "\n";
                	$rawData .= 'Content-Transfer-Encoding: binary' . "\n\n";
                	$rawData .= $operation;
        		}
        		$rawData .= '--' . $changesetBoundary . '--' . "\n";
    		    		    
    		$rawData .= '--' . $batchBoundary . '--';
		}

		// Generate URL and sign request
		$requestUrl     = $this->_credentials->signRequestUrl($this->getBaseUrl() . $path . $queryString, $resourceType, $requiredPermission);
		$requestHeaders = $this->_credentials->signRequestHeaders($httpVerb, $path, $queryString, $headers, $forTableStorage, $resourceType, $requiredPermission);

		// Prepare request
		$this->_httpClientChannel->resetParameters(true);
		$this->_httpClientChannel->setUri($requestUrl);
		$this->_httpClientChannel->setHeaders($requestHeaders);
		$this->_httpClientChannel->setRawData($rawData);
		
		// Execute request
		$response = $this->_retryPolicy->execute(
		    array($this->_httpClientChannel, 'request'),
		    array($httpVerb)
		);

		return $response;
	}
}