<?php defined('BASEPATH') or exit('No direct script access allowed');

	class Geocode {
		
		protected $ci;
		protected $cache_table = 'geocode_cache';

		private $last_call;
		private $url = "https://maps.googleapis.com/maps/api/geocode/json";

		// ----------------------------------------------------------------

		public function __construct($config = array())
		{
			$this->ci = get_instance();
			$this->ci->load->library('curl');
			log_message('debug', 'Geocode Class initialised');

			foreach ($config as $key => $value)
			{
				$this->$key = $value;
			}

			if ( ! $this->ci->db->table_exists($this->cache_table))
			{
				show_error("Geocode: The cache table '{$this->cache_table}' does not exist in the database.");
			}
		}

		// ---------------------------------------------------------------

		public function one($address_string)
		{
			$coords = array('lat' => null, 'lng' => null);

			// First check if this address has already been geocoded
			if ($cache_result = $this->cache_read($address_string))
			{
				$coords = $cache_result;
			}

			// We had a cache miss, contact the Google API
			else
			{
				$delay = 0;
				$pending = true;

				while ($pending)
				{	
					$result = $this->ci->curl->simple_get($this->url, array('sensor' => 'false', 'address' => $address_string));
					$request_info = $this->ci->curl->info;

					if ($request_info['http_code'] == 200)
					{
						// Successful response from the API
						$pending = false;
						$result = json_decode($result, true);

						if ($result['status'] == "OK")
						{
							// Cache the result and return it
							$coords = $result['results'][0]['geometry']['location'];
							$this->cache_write($address_string, $coords);
						}
						else
						{
							log_message('debug', "Geocode Class -> Google Maps responded with a 200 code but said {$result['status']}");
							$coords = false;
						}
					}
					else if ($request_info['http_code'] == 620)
					{
						log_message('debug', "Geocode Class -> Google Maps request limit exceeded (620) - throttling");
						$delay += 100000;
					}
					else
					{
						log_message('error', "Geocode Class -> Google Maps failed at the HTTP level: Server returned {$result_info['http_code']} to the query {$result_info['url']}");
						$coords = false;
						$pending = false;
					}

					usleep($delay);
				}

			}

			return $coords;
		}

		// ---------------------------------------------------------------

		protected function cache_write($address_string, $coords)
		{
			$coords = array_merge($coords, array('address' => $address_string));
			$this->ci->db->insert($this->cache_table, $coords);
			log_message('debug', "Geocode Class -> New coords written to cache for '{$address_string}'");
		}

		// ---------------------------------------------------------------

		protected function cache_read($address_string)
		{
			$result = $this->ci->db->select('lat,lng')
										 ->from($this->cache_table)
										 ->where('address', $address_string)
										 ->limit(1)->get();

			if ($result->num_rows() === 1)
			{
				log_message('debug', "Geocode Class -> Cache hit for '{$address_string}'");
				return $result->row_array();
			}
			else
			{
				log_message('debug', "Geocode Class -> Cache miss for '{$address_string}'");
				return FALSE;
			}
		}


	}