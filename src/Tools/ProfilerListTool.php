<?php

namespace MCP\ServerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[ToolAttribute('profiler_list', 'List recent Symfony profiler entries')]
class ProfilerListTool extends Tool {
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct($profilerOrConfig = null, ?array $config = null, ?ParameterBagInterface $parameterBag = null)
    {
        // Handle different constructor argument options
        if ($profilerOrConfig instanceof Profiler) {
            parent::__construct($config ?? []);
            $this->profiler = $profilerOrConfig;
        } elseif (is_array($profilerOrConfig)) {
            parent::__construct($profilerOrConfig);
        } else {
            parent::__construct($config ?? []);
        }
        
        $this->parameterBag = $parameterBag;
    }

    protected function doExecute(
        #[ParameterAttribute('limit', type: 'integer', description: 'Maximum number of profiles to return', required: false)]
        #[ParameterAttribute('ip', type: 'string', description: 'Filter by IP address', required: false)]
        #[ParameterAttribute('url', type: 'string', description: 'Filter by URL', required: false)]
        #[ParameterAttribute('method', type: 'string', description: 'Filter by HTTP method', required: false)]
        #[ParameterAttribute('status_code', type: 'integer', description: 'Filter by HTTP status code', required: false)]
        array $arguments
    ): array {
        // Set default values if not provided
        $limit = $arguments['limit'] ?? 20;
        $ip = $arguments['ip'] ?? null;
        $url = $arguments['url'] ?? null;
        $method = $arguments['method'] ?? null;
        $statusCode = $arguments['status_code'] ?? null;
        
        // Get the profiler storage
        $storage = $this->getProfilerStorage();
        if (!$storage) {
            return $this->text("Error: Profiler storage not available.");
        }
        
        // Find profiles matching the criteria
        $criteria = [];
        if ($ip !== null) {
            $criteria['ip'] = $ip;
        }
        if ($url !== null) {
            $criteria['url'] = $url;
        }
        if ($method !== null) {
            $criteria['method'] = $method;
        }
        if ($statusCode !== null) {
            $criteria['status_code'] = $statusCode;
        }
        
        try {
            $tokens = $storage->find('', '', $limit, '', '', '');
            
            if (empty($tokens)) {
                return $this->text("No profiler entries found.");
            }
            
            // Format the results
            $results = [];
            foreach ($tokens as $token) {
                // Apply additional filtering if criteria were specified
                if (!empty($criteria)) {
                    $match = true;
                    foreach ($criteria as $key => $value) {
                        if (isset($token[$key]) && $token[$key] != $value) {
                            $match = false;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }
                
                $results[] = [
                    'token' => $token['token'],
                    'ip' => $token['ip'],
                    'method' => $token['method'],
                    'url' => $token['url'],
                    'time' => date('Y-m-d H:i:s', $token['time']),
                    'status_code' => $token['status_code']
                ];
            }
            
            if (empty($results)) {
                return $this->text("No profiler entries match the specified criteria.");
            }
            
            return $this->text(json_encode($results, JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            return $this->text("Error retrieving profiler entries: " . $e->getMessage());
        }
    }
    
    /**
     * Get the profiler storage
     */
    private function getProfilerStorage()
    {
        // If profiler is already set, get its storage
        if ($this->profiler !== null) {
            $reflection = new \ReflectionClass($this->profiler);
            $storageProperty = $reflection->getProperty('storage');
            $storageProperty->setAccessible(true);
            return $storageProperty->getValue($this->profiler);
        }
        
        // Try to get profiler from container
        if (self::$container === null) {
            // Try to get the container from the global kernel
            global $kernel;
            if (isset($kernel) && method_exists($kernel, 'getContainer')) {
                self::$container = $kernel->getContainer();
            } else if (class_exists('\\Symfony\\Component\\HttpKernel\\KernelInterface')) {
                // Try to get kernel from bootstrap cache if available
                $kernelFile = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
                $kernelFile = rtrim($kernelFile, '/') . '/var/cache/dev/App_KernelDevDebugContainer.php';
                if (file_exists($kernelFile)) {
                    require_once $kernelFile;
                    if (class_exists('\\App_KernelDevDebugContainer')) {
                        $container = new \App_KernelDevDebugContainer();
                        self::$container = $container;
                    }
                }
            }
        }
        
        // Get profiler from container
        if (self::$container !== null && self::$container->has('profiler')) {
            $this->profiler = self::$container->get('profiler');
            $reflection = new \ReflectionClass($this->profiler);
            $storageProperty = $reflection->getProperty('storage');
            $storageProperty->setAccessible(true);
            return $storageProperty->getValue($this->profiler);
        }
        
        // Try to create a storage directly
        $configuredPath = null;
        
        if ($this->parameterBag !== null && $this->parameterBag->has('mcp_server.profiler.storage_path')) {
            $configuredPath = $this->parameterBag->get('mcp_server.profiler.storage_path');
        } elseif (self::$container !== null && self::$container->hasParameter('mcp_server.profiler.storage_path')) {
            $configuredPath = self::$container->getParameter('mcp_server.profiler.storage_path');
        }
        
        // Check if configured path exists and is usable
        if ($configuredPath && is_dir($configuredPath)) {
            return new FileProfilerStorage('file:' . $configuredPath);
        }
        
        // Common storage locations to try
        $storagePaths = [
            getcwd() . '/var/cache/dev/profiler',
            $_SERVER['DOCUMENT_ROOT'] . '/var/cache/dev/profiler',
            sys_get_temp_dir() . '/symfony/profiler'
        ];
        
        foreach ($storagePaths as $path) {
            if (is_dir($path)) {
                return new FileProfilerStorage('file:'.$path);
            }
        }
        
        return null;
    }
}