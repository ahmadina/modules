<?php namespace Pingpong\Modules;

use Countable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Pingpong\Modules\Contracts\RepositoryInterface;
use Pingpong\Modules\Exceptions\ModuleNotFoundException;
use Pingpong\Modules\Process\Updater;
use Pingpong\Modules\Process\Installer;

class Repository implements RepositoryInterface, Countable {

    /**
     * Application instance.
     *
     * @var Application
     */
    protected $app;

    /**
     * The module path.
     *
     * @var string|null
     */
    protected $path;

    /**
     * The constructor.
     *
     * @param Application $app
     * @param string|null $path
     */
    public function __construct(Application $app, $path = null)
    {
        $this->app = $app;
        $this->path = $path;
    }

    /**
     * Get all modules.
     *
     * @return array
     */
    public function all()
    {
        $modules = [];

        if ( ! $this->app['files']->isDirectory($path = $this->getPath())) return $modules;

        $directories = $this->app['files']->directories($path);

        foreach ($directories as $module)
        {
            if ( ! Str::startsWith($name = basename($module), '.'))
            {
                $modules[$name] = new Module($this->app, $name, $module);
            }
        }

        return $modules;
    }

    /**
     * Get modules by status.
     *
     * @param $status
     * @return array
     */
    public function getByStatus($status)
    {
        $modules = [];

        foreach ($this->all() as $name => $module)
        {
            if ($module->isStatus($status))
            {
                $modules[$name] = $module;
            }
        }

        return $modules;
    }

    /**
     * Determine whether the given module exist.
     *
     * @param $name
     * @return bool
     */
    public function has($name)
    {
        return array_key_exists($name, $this->all());
    }

    /**
     * Get list of enabled modules.
     *
     * @return array
     */
    public function enabled()
    {
        return $this->getByStatus(1);
    }

    /**
     * Get list of disabled modules.
     *
     * @return array
     */
    public function disabled()
    {
        return $this->getByStatus(0);
    }

    /**
     * Get count from all modules.
     *
     * @return int
     */
    public function count()
    {
        return count($this->all());
    }

    /**
     * Get all ordered modules.
     *
     * @return array
     */
    public function getOrdered()
    {
        $modules = $this->enabled();

        uasort($modules, function ($a, $b)
        {
            if ($a->priority == $b->priority)
            {
                return 0;
            }

            return $a->priority < $b->priority ? 1 : -1;
        });


        return $modules;
    }

    /**
     * Get a module path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path ?: $this->app['config']->get('modules::paths.modules');
    }

    /**
     * Register the modules.
     *
     * @return void
     */
    public function register()
    {
        foreach ($this->getOrdered() as $module)
        {
            $module->register();
        }
    }

    /**
     * Boot the modules.
     *
     * @return void
     */
    public function boot()
    {
        foreach ($this->getOrdered() as $module)
        {
            $module->boot();
        }
    }

    /**
     * Find a specific module.
     *
     * @param $name
     * @return null
     */
    public function find($name)
    {
        foreach ($this->all() as $module)
        {
            if ($module->getLowerName() == strtolower($name))
            {
                return $module;
            }
        }

        return null;
    }

    /**
     * Alternate for "find" method.
     *
     * @param $name
     * @return null
     */
    public function get($name)
    {
        return $this->find($name);
    }

    /**
     * Find a specific module, if there return that, otherwise throw exception.
     *
     * @param $name
     * @return mixed|void
     * @throws ModuleNotFoundException
     */
    public function findOrFail($name)
    {
        if ( ! is_null($module = $this->find($name)))
        {
            return $module;
        }

        throw new ModuleNotFoundException("Module [{$name}] does not exist!");
    }

    /**
     * Get all modules as laravel collection instance.
     *
     * @return Collection
     */
    public function collections()
    {
        return new Collection($this->enabled());
    }

    /**
     * Get module path for a specific module.
     *
     * @param $module
     * @return string
     */
    public function getModulePath($module)
    {
        $module = Str::studly($module);

        return $this->getPath() . "/{$module}/";
    }

    /**
     * Get asset path for a specific module.
     *
     * @param $module
     * @return string
     */
    public function assetPath($module)
    {
        return $this->config('assets') . '/' . $module;
    }

    /**
     * Get a specific config data from a configuration file.
     *
     * @param $key
     * @return mixed
     */
    public function config($key)
    {
        return $this->app['config']->get('modules::paths.' . $key);
    }

    /**
     * Get storage path for module used.
     *
     * @return string
     */
    public function getUsedStoragePath()
    {
        if( ! $this->app['files']->exists($path = storage_path('meta')))
        {
            $this->app['files']->makeDirectory($path, 0777, true);
        }

        return $path . '/modules.used';
    }

    /**
     * Set module used for cli session.
     *
     * @param $name
     * @throws ModuleNotFoundException
     */
    public function setUsed($name)
    {
        $module = $this->findOrFail($name);

        $this->app['files']->put($this->getUsedStoragePath(), $module);
    }

    /**
     * Get module used for cli session.
     *
     * @return string
     */
    public function getUsedNow()
    {
        return $this->findOrFail($this->app['files']->get($this->getUsedStoragePath()));
    }

    /**
     * Get used now.
     * 
     * @return string
     */
    public function getUsed()
    {
        return $this->getUsedNow();
    }

    /**
     * Get laravel filesystem instance.
     *
     * @return \Illuminate\Filesystem\Filesystem
     */
    public function getFiles()
    {
        return $this->app['files'];
    }

    /**
     * Get module assets path.
     *
     * @return string
     */
    public function getAssetsPath()
    {
        return $this->config('assets');
    }

    /**
     * Get asset url from a specific module.
     * 
     * @param  string  $asset
     * @param  boolean $secure
     * @return string
     */
    public function asset($asset, $secure = false)
    {
        list($name, $url) = explode(':', $asset);

        return $this->app['url']->asset(basename($this->getAssetsPath()) . "/{$name}/" . $url, $secure);
    }
    
    /**
     * Determine whether the given module is activated.
     * 
     * @param  string $name
     * @return boolean
     */
    public function active($name)
    {
        return $this->findOrFail($name)->active();
    }
    
    /**
     * Determine whether the given module is not activated.
     * 
     * @param  string $name
     * @return boolean
     */
    public function notActive($name)
    {
        return ! $this->active($name);
    }

    /**
     * Enabling a specific module.
     * 
     * @param  string $name
     * @return bool
     */
    public function enable($name)
    {
        return $this->findOrFail($name)->enable();
    }

    /**
     * Disabling a specific module.
     * 
     * @param  string $name
     * @return bool
     */
    public function disable($name)
    {
        return $this->findOrFail($name)->disable();
    }
    
    /**
     * Update dependencies for the specified module.
     *
     * @param  string $module
     * @return void
     */
    public function update($module)
    {
        with(new Updater($this))->update($module);
    }

    /**
     * Install the specified module.
     *
     * @param  string $name
     * @param  string $path
     * @param bool $subtree
     * @return void
     */
    public function install($name, $path = null, $subtree = false)
    {
        with(new Installer($this))->install($name, $path, $subtree);
    }

}