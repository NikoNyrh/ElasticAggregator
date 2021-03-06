<?php
namespace NikoNyrh\ElasticAggregator;

class AggregationResponse
{
	protected $esClient;
	protected $config;
	
	// A lazy way to toggle debug printing :P
	protected static function debug($msg) {
		// echo $msg;
	}
	
	public function __construct($esClient, array $config = array())
	{
		$this->esClient = $esClient;
		$this->config   = $config;
	}
	
	public function setIndex($index) {
		$this->config['index'] = $index;
		return $this;
	}
	
	public function exec($query, $type, $config=array())
	{
		$hasFlag = function ($key) use ($config) {
			return
				(isset($config[$key]) && $config[$key]) || (
				isset($config['flags']) && isset($config['flags'][$key])
			);
		};
		
		if ($hasFlag('getOrigin')) {
			return $query->getAggregates();
		}
		
		$search = array(
			'index' => $this->config['index'],
			'type'  => $type,
			'body'  => $query->buildBody()
		);
		
		$hasQuery = isset($config['query']) && !empty($config['query']);
		
		if ($hasQuery) {
			if (isset($config['query']['query'])) {
				$search['body']['query'] = $config['query']['query'];
				$search['body']['size']  = isset($config['query']['size']) ?
					$config['query']['size'] : 0;
			}
			else {
				$search['body']['query'] = $config['query'];
				$search['body']['size']  = 0;
			}
			
			if (isset($config['query']['sort'])) {
				$search['body']['sort'] = $config['query']['sort'];
			}
			
			if (isset($config['query']['highlight'])) {
				$search['body']['highlight'] = $config['query']['highlight'];
			}
		}
		
		if ($hasFlag('getSearch')) {
			return $search;
		}
		
		$response = $this->esClient->search($search);
		
		if ($hasFlag('getResponse')) {
			return $response;
		}
		
		$keysToSkip = array(
			'doc_count_error_upper_bound',
			'sum_other_doc_count'
		);
		
		$removeKeysToSkip = function (&$arr) use (&$removeKeysToSkip, $keysToSkip) {
			if (!is_array($arr)) {
				return;
			}
			
			foreach ($keysToSkip as $key) {
				if (isset($arr[$key])) {
					unset($arr[$key]);
				}
			}
			
			foreach (array_keys($arr) as $key) {
				$removeKeysToSkip($arr[$key]);
			}
		};
		
		$removeKeysToSkip($response);
		
		$parse = function ($response) use (&$parse) {
			if (!is_array($response)) {
				return $response;
			}
			
			if (sizeof($response) == 1) {
				$key = array_keys($response);
				$key = $key[0];
				
				if (isset($response[$key]['buckets'])) {
					self::debug(sprintf(
						"Case 1a (%s): %s\n",
						$key, print_r($response[$key], true)
					));
					
					$result = array();
					foreach ($response[$key]['buckets'] as $bucket) {
						if (isset($bucket['key'])) {
							$resultKey = $bucket['key'];
							
							if (
								isset($bucket['key_as_string']) &&
								is_int($resultKey) && ($resultKey % 1000) == 0
							) {
								$asDate = (new \DateTime(
									'@' . ($resultKey/1000)
								))->format('Y-m-d H:i:s');
								
								if ($resultKey < 24*60*60*1000) {
									$asDate = explode(' ', $asDate);
									$resultKey = $asDate[1];
								}
								else {
									$resultKey = $asDate;
								}
							}
							
							unset($bucket['key']);
						}
						else {
							$resultKey = sizeof($result);
						}
						
						if (isset($bucket['key_as_string'])) {
							unset($bucket['key_as_string']);
						}
						
						if (sizeof($bucket) > 1) {
							unset($bucket['doc_count']);
						}
						
						$result[$resultKey] = $parse($bucket);
					}
					
					return $result;
				}
				elseif (
					sizeof($response[$key]) > 1 &&
					isset($response[$key]['doc_count'])
				) {
					self::debug(sprintf(
						"Case 1b (%s): %s\n",
						$key, print_r($response[$key], true)
					));
					
					unset($response[$key]['doc_count']);
					return $parse($response[$key]);
				}
				elseif (
					$key == 'values' || // from percentiles aggregation
					$key == 'value'  || // from sum aggregation
					( // can also be nested aggregation
						preg_match('/\.value[s]?/', $key) &&
						is_array($response[$key]) &&
						sizeof($response[$key]) == 1
					)
				) {
					return $parse($response[$key]);
				}
				elseif (
					sizeof($response[$key]) == 1 &&
					isset($response[$key]['values'])
				) {
					// Presumably from percentiles aggregation
					return $response[$key]['values'];
				}
				else {
					self::debug(sprintf(
						"Case 1c (%s): %s\n",
						$key, print_r($response[$key], true)
					));
				}
			}
			elseif (
				isset($response['parent'])
			) {
				self::debug(sprintf(
					"Case 2a: %s\n", print_r($response, true)
				));
				
				$parent = $response['parent'];
				unset($response['parent']);
				
				$tmp = $parse($response);
				$tmp['parent'] = $parse(array('parent' => $parent));
				return $tmp;
			}
			else {
				self::debug(sprintf(
					"Case 2b %s\n", print_r($response, true)
				));
			}
			
			if (
				sizeof($response) == 1 &&
				isset($response['doc_count']) &&
				!is_array($response['doc_count'])
			) {
				return $response['doc_count'];
			}
			
			$result = array();
			
			foreach ($response as $key => $value) {
				if (preg_match('/_agg$/', $key)) {
					$parsed = $parse(array($key => $value));
				}
				else {
					$parsed = $parse($value);
				}
				
				$resultKey = preg_replace('/_(stats|metric|agg)(_[0-9]+)?$/', '', $key);
				
				if ($resultKey == '_parent') {
					$resultKey = 'parent';
				}
				
				if (!isset($result[$resultKey])) {
					$result[$resultKey] = $parsed;
				}
				else {
					if (!isset($result[$resultKey][0])) {
						$result[$resultKey] = array($result[$resultKey]);
					}
					
					$result[$resultKey][] = $parsed;
				}
			}
			
			return $result;
		};
		
		$result = isset($response['aggregations']) ?
			$parse($response['aggregations']) :
			null;
		
		self::debug(sprintf("Result: %s\n", print_r($result, true)));
		
		if ($hasFlag('object2array')) {
			$result = $this->object2array($result);
		}
		
		if (!$hasQuery || $search['body']['size'] <= 0) {
			return $hasFlag('getHits') ? array(
				'totalHits' => $response['hits']['total'],
				'result'    => $result
			) : $result;
		}
		
		$hits = array();
		foreach ($response['hits']['hits'] as $hit) {
			$hit['_source']['_score'] = $hit['_score'];
			
			if (isset($hit['highlight'])) {
				$hit['_source']['_highlight'] = $hit['highlight'];
			}
			
			$hits[] = $hit['_source'];
		}
		
		return array(
			'totalHits' => $response['hits']['total'],
			'hits'      => $hits,
			'aggs'      => $result
		);
	}
	
	protected function object2array($arr, $iter=0) {
		// PHP preserves key-value array order but in general JSON does not.
		if (!is_array($arr) || empty($arr)) {
			return $arr;
		}
		
		$keys = array_keys($arr);
		
		if (!is_array($arr[$keys[0]])) {
			return $arr;
		}
		
		$result = array();
		foreach ($keys as $key) {
			$result[] = array(
				'key'   => $key,
				'value' => $this->object2array($arr[$key])
			);
		}
		return $result;
	}
}
