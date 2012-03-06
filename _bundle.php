<?php

namespace Bundles\Portal;
use Bundles\Router\NotFoundException;
use Exception;
use stack;
use e;

class Bundle {
	public static $currentPortalDir;
	public static $currentPortalName;
	public static $currentException;

	/**
	 * Portal hook access
	 */
	public function __callBundle($path) {
		return new PortalHookAccessor($path);
	}
	
	/**
	 * Add LHTML Hook
	 * @author Robbie Trencheny
	 */
	public function _on_framework_loaded() {
		e::configure('lhtml')->activeAddKey('hook', ':portal', function() {
			return function() {
				$class = __CLASS__;
				$slug = $class::$currentPortalName;
				return array(
					'slug' => $slug
				);
			};
		});
	}

	/**
	 * Route the portal
	 */
	public function _on_router_route($path) {
		/**
		 * Add the site dir to portal locations
		 */
		e::configure('portal')->activeAdd('locations', e\site);
		
		/**
		 * Check for null first segment
		 */
		if(!isset($path[0]))
			$name = 'site';
			
		/**
		 * Portal Name
		 */
		else
			$name = strtolower($path[0]);

		/**
		 * Paths where this portal exists
		 */
		$matched = null;

		/**
		 * Get portal paths
		 */
		$searchdirs = e::configure('portal')->locations;
		
		/**
		 * Check for portal in paths
		 */
		foreach($searchdirs as $dir) {
			$dir .= '/portals/' . $name;
			if(is_dir($dir)) {
				$matched = $dir;
				break;
			}
		}
		
		/**
		 * Search the default portal
		 */
		if(is_null($matched)) foreach($searchdirs as $dir) {
			$name = 'site';
			
			$dir .= '/portals/' . $name;
			if(is_dir($dir)) {
				$matched = $dir;
				array_unshift($path, $name);
				break;
			}
		}
		
		/**
		 * If any paths matched
		 */
		if(!is_null($matched)) {

			/**
			 * Remove the first segment
			 */
			$shifted = array_shift($path);
			
			/**
			 * URL
			 */
			$url = implode('/', $path);
			
			/**
			 * Save current portal location
			 */
			self::$currentPortalDir = $matched;

			/**
			 * Save current portal name
			 */
			self::$currentPortalName = $name;

			try {
				
				/**
				 * Route inside of the portal
				 */
				e::$events->portal_route($path, $matched, "allow:$matched/portal.yaml");
				
				/**
				 * If nothing found, throw exception
				 */
				throw new NotFoundException("Resource `$url` not found in portal `$matched`");
			}

			/**
			 * If page not found, try in site portal before giving up
			 */
			catch(NotFoundException $e) {
				if($shifted !== 'site') {
				 	array_unshift($path, 'site', $shifted);
				 	try { $this->_on_router_route($path); }
				 	catch(NotFoundException $void) {
				 		throw $e;
				 	}
				}
				else throw $e;
			}
			
			/**
			 * Handle any exceptions
			 */
			catch(Exception $exception) {

				/**
				 * Broadcast the exception
				 */
				e::$events->exception($exception);

				/**
				 * Current Exception
				 */
				self::$currentException = $exception;
			
				/**
				 * Try to resolve with error pages
				 */
				foreach(array(self::$currentPortalDir, dirname(self::$currentPortalDir) . '/site') as $portal) {
					try {
						e::$events->portal_exception($path, $portal, $exception);
					} catch(Exception $exception) {}
				}

				/**
				 * Reset Current Exception
				 */
				self::$currentException = null;
			
				/**
				 * Throw if not completed
				 */
				throw $exception;
			}
		}
	}

	/**
	 * Show portal directories
	 */
	public function _on_message_info() {

		/**
		 * Don't show if not in a portal
		 */
		if(self::$currentPortalDirs === null)
			return '';

		$out = '<h4>Portal Locations</h4><div class="trace">';
		foreach(e::configure('portal')->locations as $dir) {
			
			/**
			 * Get portals in dir
			 */
			$list = glob("$dir/*", GLOB_ONLYDIR);
			foreach($list as $index => $item) {
				$list[$index] = basename($list[$index]);
				if(in_array($item, self::$currentPortalDirs))
					$list[$index] = '<span class="class selected" title="This is the current portal">'.$list[$index].'</span>';
				else
					$list[$index] = '<span class="class">'.$list[$index].'</span>';
			}
			$portals = implode(' &bull; ', $list);
			if($portals != '')
				$portals = ": $portals";
			$out .= '<div class="step"><span class="file">'.$dir.$portals.'</span></div>';
		}
		$out .= '</div>';
		return $out;
	}
	
	public function currentPortalDir() {
		return self::$currentPortalDir;
	}
	
	public function currentPortalName() {
		return self::$currentPortalName;
	}
	
}

/**
 * Portal hook accessor
 * @author Nate Ferrero
 */
class PortalHookAccessor {

	/**
	 * Saved path
	 */
	private $path;
	
	/**
	 * Save path
	 */
	public function __construct($path) {
		$this->path = e\site . '/portals/' . $path;
		$this->class = '\\Portals\\' . str_replace('/', '\\', $path);
	}

	/**
	 * Get a hook
	 */
	public function __get($hook) {

		/**
		 * Load active hooks
		 */
		$hooks = e::configure('portal')->hook;

		if(isset($hooks[$hook])) {
			$hook = $hooks[$hook];

			/**
			 * If the hook is a function, pass the path to it and return
			 */
			if(is_callable($hook))
				return $hook($this->path, $this->class);

			/**
			 * Otherwise, return the hook
			 */
			return $hook;
		}

		/**
		 * Hook is not defined
		 */
		throw new Exception("Portal hook `$hook` is not defined");
	}

}