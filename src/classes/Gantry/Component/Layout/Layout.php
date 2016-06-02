<?php
/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2016 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\Layout;

use Gantry\Component\Config\Config;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Folder;
use Gantry\Framework\Outlines;
use Gantry\Framework\Gantry;
use RocketTheme\Toolbox\ArrayTraits\ArrayAccess;
use RocketTheme\Toolbox\ArrayTraits\Export;
use RocketTheme\Toolbox\ArrayTraits\ExportInterface;
use RocketTheme\Toolbox\ArrayTraits\Iterator;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\File\YamlFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceIterator;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Layout
 */
class Layout implements \ArrayAccess, \Iterator, ExportInterface
{
    use ArrayAccess, Iterator, Export;

    const VERSION = 5;

    protected static $instances = [];
    protected static $indexes = [];
    protected $layout = ['wrapper', 'container', 'section', 'grid', 'block', 'offcanvas'];

    public $name;
    public $timestamp = 0;
    public $preset = [];
    public $equalized = [3 => 33.3, 6 => 16.7, 7 => 14.3, 8 => 12.5, 9 => 11.1, 11 => 9.1, 12 => 8.3];

    protected $exists;
    protected $items;
    protected $references;
    protected $blocks;
    protected $types;
    protected $inherit;

    /**
     * @return array
     */
    public static function presets()
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        /** @var UniformResourceIterator $iterator */
        $iterator = $locator->getIterator(
            'gantry-layouts://',
            UniformResourceIterator::CURRENT_AS_SELF | UniformResourceIterator::UNIX_PATHS | UniformResourceIterator::SKIP_DOTS
        );

        $files = [];
        /** @var UniformResourceIterator $info */
        foreach ($iterator as $info) {
            $name = $info->getBasename('.yaml');
            if (!$info->isFile() || $info->getExtension() != 'yaml' || $name[0] == '.') {
                continue;
            }
            $files[] = $name;
        }

        sort($files);

        $results = ['user' => [], 'system' => []];
        foreach ($files as $preset) {
            $scope = $preset && $preset[0] !== '_' ? 'user' : 'system';
            $results[$scope][$preset] = ucwords(trim(preg_replace(['|_|', '|/|'], [' ', ' / '], $preset)));
        }

        return $results;
    }

    /**
     * @param string $name
     * @return array
     * @throws \RuntimeException
     */
    public static function preset($name)
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];
        $filename = $locator->findResource("gantry-layouts://{$name}.yaml");

        if (!$filename) {
            throw new \RuntimeException("Preset '{$name}' not found", 404);
        }

        $layout = LayoutReader::read($filename);
        $layout['preset']['name'] = $name;
        $layout['preset']['timestamp'] = filemtime($filename);

        return $layout;
    }

    /**
     * @param  string $name
     * @return Layout
     */
    public static function instance($name)
    {
        if (!isset(static::$instances[$name])) {
            static::$instances[$name] = static::load($name);
        }

        return static::$instances[$name];
    }

    /**
     * @param  string $name
     * @return Layout
     */
    public static function index($name)
    {
        if (!isset(static::$indexes[$name])) {
            static::$indexes[$name] = static::loadIndex($name, true);
        }

        return static::$indexes[$name];
    }

    /**
     * @param string $name
     * @param array $items
     * @param array $preset
     */
    public function __construct($name, array $items = null, array $preset = null)
    {
        $this->name = $name;
        $this->items = (array) $items;
        $this->exists = $items !== null;

        // Add preset data from the layout.
        if ($preset) {
            $this->preset = $preset;
        } elseif (isset($this->items['preset'])) {
            $this->preset = (array) $this->items['preset'];
        }

        unset($this->items['preset']);

        $this->preset += [
            'name' => '',
            'timestamp' => 0,
            'image' => 'gantry-admin://images/layouts/default.png'
        ];
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->exists;
    }

    /**
     * Initialize layout.
     *
     * @param  bool  $force
     * @return $this
     */
    public function init($force = false)
    {
        if ($force || !isset($this->references)) {
            $this->initReferences();
            $this->initInheritance();
        }

        return $this;
    }

    /**
     * Build separate meta-information from the layout.
     *
     * @return array
     */
    public function buildIndex()
    {
        return [
            'name' => $this->name,
            'timestamp' => $this->timestamp,
            'version' => static::VERSION,
            'preset' => $this->preset,
            'positions' => $this->positions(),
            'sections' => $this->sections(),
            'particles' => $this->particles(),
            'inherit' => $this->inherit()
        ];
    }

    /**
     * @param string $old
     * @param string $new
     * @param array  $ids
     * @return $this
     */
    public function updateInheritance($old, $new = null, $ids = null)
    {
        $this->init();

        $inherit = $this->inherit();

        if (!empty($inherit[$old])) {
            foreach ($inherit[$old] as $id) {
                $element = $this->find($id);
                $inheritId = isset($element->inherit->particle) ? $element->inherit->particle : $id;
                if ($new && ($ids === null || isset($ids[$inheritId]))) {
                    $element->inherit->outline = $new;
                } else {
                    $element->inherit = new \stdClass;
                    unset($this->inherit[$element->id]);
                }
            }
        }

        return $this;
    }


    /**
     * Save layout.
     *
     * @param bool $cascade
     * @return $this
     */
    public function save($cascade = true)
    {
        if (!$this->name) {
            throw new \LogicException('Cannot save unnamed layout');
        }

        $name = strtolower(preg_replace('|[^a-z\d_-]|ui', '_', $this->name));

        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        // If there are atoms in the layout, copy them into outline configuration.
        $atoms = $this->atoms();
        if (is_array($atoms)) {
            if ($cascade) {
                // Save layout into custom directory for the current theme.
                $filename = $locator->findResource("gantry-config://{$name}/page/head.yaml", true, true);

                $file = YamlFile::instance($filename);
                $config = new Config($file->content());

                $file->save($config->set('atoms', json_decode(json_encode($atoms), true))->toArray());
                $file->free();
            }
        }

        // Remove atoms from the layout.
        foreach ($this->items as $key => $section) {
            if ($section->type === 'atoms') {
                unset ($this->items[$key]);
            }
        }

        $filename = $locator->findResource("gantry-config://{$name}/layout.yaml", true, true);
        $file = CompiledYamlFile::instance($filename);
        $file->settings(['inline' => 20]);
        $file->save(LayoutReader::store($this->preset, $this->items));
        $file->free();

        $this->timestamp = $file->modified();
        $this->exists = true;

        return $this;
    }

    /**
     * Save index.
     *
     * @return $this
     */
    public function saveIndex($index = null)
    {
        if (!$this->name) {
            throw new \LogicException('Cannot save unnamed layout');
        }

        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];
        $filename = $locator->findResource("gantry-config://{$this->name}/index.yaml", true, true);
        $cache = $locator->findResource("gantry-cache://{$this->name}/compiled/yaml", true, true);
        $file = CompiledYamlFile::instance($filename);

        // Attempt to lock the file for writing.
        try {
            $file->lock(false);
        } catch (\Exception $e) {
            // Another process has locked the file; we will check this in a bit.
        }

        $index = $index ? $index : $this->buildIndex();

        // If file wasn't already locked by another process, save it.
        if ($file->locked() !== false) {
            $file->setCachePath($cache)->settings(['inline' => 20]);
            $file->save($index);
            $file->unlock();
        }
        $file->free();

        static::$indexes['name'] = $index;

        return $this;
    }

    /**
     * @return array
     */
    public function getLayoutTypes()
    {
        return $this->layout;
    }

    /**
     * @param string $type
     * @return bool
     */
    public function isLayoutType($type)
    {
        return in_array($type, $this->layout);
    }

    /**
     * @return array
     */
    public function references()
    {
        $this->init();

        return $this->references;
    }

    /**
     * @param string $type
     * @param string $subtype
     * @return array
     */
    public function referencesByType($type = null, $subtype = null)
    {
        $this->init();

        if (!$type) {
            return $this->types;
        } elseif (!$subtype) {
            return isset($this->types[$type]) ? $this->types[$type] : [];
        }
        return isset($this->types[$type][$subtype]) ? $this->types[$type][$subtype] : [];
    }

    /**
     * Return list of positions (key) with their titles (value).
     *
     * @return array Array of position => title
     */
    public function positions()
    {
        $positions = $this->referencesByType('position', 'position');

        $list = [];
        foreach($positions as $position) {
            if (!isset($position->attributes->key)) {
                continue;
            }
            $list[$position->attributes->key] = $position->title;
        }

        return $list;
    }

    /**
     * Return list of positions (key) with their titles (value).
     *
     * @return array Array of position => title
     */
    public function sections()
    {
        $list = [];
        foreach ($this->referencesByType('section') as $type => $sections) {
            foreach ($sections as $id => $section) {
                $list[$id] = $section->title;
            }
        }

        foreach ($this->referencesByType('offcanvas') as $type => $sections) {
            foreach ($sections as $id => $section) {
                $list[$id] = $section->title;
            }
        }

        return $list;
    }

    /**
     * Return list of particles with their titles.
     *
     * @param  bool  $grouped  If true, group particles by type.
     * @return array Array of position => title
     */
    public function particles($grouped = true)
    {
        $blocks = $this->referencesByType('block', 'block');

        $list = [];
        foreach ($blocks as $blockId => $block) {
            foreach ($block->children as $id => $particle) {
                if (!empty($particle->layout) || in_array($particle->type, $this->layout)) {
                    continue;
                }
                if ($grouped) {
                    $list[$particle->subtype][$particle->id] = $particle->title;
                } else {
                    $list[$particle->id] = $particle->title;
                }
            }
        }

        return $list;
    }

    /**
     * @param string $outline
     * @return array
     */
    public function inherit($outline = null)
    {
        $this->init();

        $list = [];
        foreach ($this->inherit as $name => $item) {
            if (isset($item->inherit->particle)) {
                $list[$item->inherit->outline][$item->inherit->particle] = $name;
            } else {
                $list[$item->inherit->outline][$name] = $name;
            }
        }

        return $outline ? (!empty($list[$outline]) ? $list[$outline] : []) : $list;
    }

    /**
     * Return atoms from the layout.
     *
     * @return array|null
     * @deprecated
     */
    public function atoms()
    {
        $list   = null;

        $atoms = array_filter($this->items, function ($section) {
            return $section->type == 'atoms' && !empty($section->children);
        });
        $atoms = array_shift($atoms);

        if (!empty($atoms->children)) {
            $list = [];
            foreach ($atoms->children as $grid) {
                if (!empty($grid->children)) {
                    foreach ($grid->children as $block) {
                        if (isset($block->children[0])) {
                            $item = $block->children[0];
                            $list[] = ['title' => $item->title, 'type' => $item->subtype, 'attributes' => $item->attributes];
                        }
                    }
                }
            }
        }

        return $list;
    }

    /**
     * @param string $id
     * @return \stdClass
     */
    public function find($id)
    {
        $this->init();

        if (!isset($this->references[$id])) {
            return (object)['id' => $id];
        }

        return $this->references[$id];
    }

    /**
     * @param string $id
     * @return null
     */
    public function block($id)
    {
        $this->init();

        return isset($this->blocks[$id]) ? $this->blocks[$id] : null;
    }

    public function clearSections()
    {
        $this->items = $this->clearChildren($this->items);

        return $this;
    }

    protected function clearChildren(&$items)
    {
        foreach ($items as $key => $item) {
            if (!empty($item->children)) {
                $this->children = $this->clearChildren($item->children);
            }

            if (empty($item->children) && in_array($item->type, ['grid', 'block', 'particle', 'position', 'spacer', 'system'])) {
                unset($items[$key]);
            }
        }

        return array_values($items);
    }

    public function copySections(array $old)
    {
        $this->init();

        /** @var Layout $old */
        $old = new static('tmp', $old);

        $leftover = [];

        // Copy normal sections.
        $data = $old->referencesByType('section');

        if (isset($this->types['section'])) {
            $sections = $this->types['section'];

            $this->copyData($data, $sections, $leftover);
        }

        // Copy offcanvas.
        $data = $old->referencesByType('offcanvas');
        if (isset($this->types['offcanvas'])) {
            $offcanvas = $this->types['offcanvas'];

            $this->copyData($data, $offcanvas, $leftover);
        }

        // Copy atoms.
        $data = $old->referencesByType('atoms');
        if (isset($this->types['atoms'])) {
            $atoms = $this->types['atoms'];

            $this->copyData($data, $atoms, $leftover);
        }

        return $leftover;
    }

    public function inheritAll()
    {
        foreach ($this->references() as $item) {
            if (!empty($item->inherit->outline)) {
                continue;
            }
            if (!$this->isLayoutType($item->type)) {
                $item->inherit = (object) ['outline' => $this->name, 'include' => ['attributes', 'block']];
            } elseif ($item->type === 'section' || $item->type == 'offcanvas') {
                $item->inherit = (object) ['outline' => $this->name, 'include' => ['attributes', 'block', 'children']];
            }
        }

        $this->init(true);

        return $this;
    }

    protected function copyData(array $data, array $sections, array &$leftover)
    {
        foreach ($data as $type => $items) {
            foreach ($items as $item) {
                $found = false;
                if (isset($sections[$type])) {
                    foreach ($sections[$type] as $section) {
                        if ($section->id === $item->id) {
                            $found = true;
                            $section->inherit = $item->inherit;
                            $section->children = $item->children;
                            break;
                        }
                    }
                }
                if (!$found && !empty($item->children)) {
                    $leftover[$item->id] = $item->title;
                }
            }
        }
    }

    protected function initInheritance()
    {
        if ($this->name) {
            $gantry = Gantry::instance();

            /** @var UniformResourceLocator $locator */
            $locator = $gantry['locator'];

            // Attempt to load the index file.
            $indexFile = $locator("gantry-config://{$this->name}/index.yaml");
            if ($indexFile) {
                $file = CompiledYamlFile::instance($indexFile);
                $index = $file->content();
                $file->free();
            }
        } else {
            $index = null;
        }

        foreach ($this->inherit() as $outlineId => $list) {
            try {
                $outline = $this->instance($outlineId);
            } catch (\Exception $e) {
                // Outline must have been deleted.
                $outline = null;
            }
            foreach ($list as $id) {
                $item = $this->find($id);

                $inheritId = !empty($item->inherit->particle) ? $item->inherit->particle : $id;
                $inherited = $outline ? $outline->find($inheritId) : null;
                $include = $item->inherit->include;

                foreach ($include as $part) {
                    switch ($part) {
                        case 'attributes':
                            $item->attributes = isset($inherited->attributes) ? $inherited->attributes : new \stdClass();
                            break;
                        case 'block':
                            $block = $this->block($id);
                            if (isset($block->attributes)) {
                                $inheritBlock = $outline ? $outline->block($inheritId) : null;
                                $blockAttributes = $inheritBlock ? array_diff_key((array)$inheritBlock->attributes, ['fixed' => 1, 'size' => 1]) : [];
                                $block->attributes = (object)($blockAttributes + (array)$block->attributes);
                            }
                            break;
                        case 'children':
                            if (!empty($inherited->children)) {
                                $item->children = $inherited->children;
                                $this->initReferences($item->children, null, ['outline' => $outlineId, 'include' => ['attributes', 'block']], $index);
                            } else {
                                $item->children = [];
                            }
                            break;
                    }
                }

                if (!$outline || !isset($inherited->attributes)) {
                    // Remvoe inheritance information if outline doesn't exist.
                    $item->inherit = new \stdClass;
                    unset($this->inherit[$item->id]);
                }
            }
        }

    }

    /**
     * @param array $items
     * @param object $block
     * @param string $inherit
     * @param array $index
     */
    protected function initReferences(array $items = null, $block = null, $inherit = null, array $index = null)
    {
        if ($items === null) {
            $items = $this->items;
            $this->references = [];
            $this->types = [];
            $this->inherit = [];
        }

        foreach ($items as $item) {
            if (is_object($item)) {
                $type = $item->type;
                $subtype = !empty($item->subtype) ? $item->subtype : $type;

                if ($block) {
                    $this->blocks[$item->id] = $block;
                }

                if ($inherit && !$this->isLayoutType($type)) {
                    $item->inherit = (object) $inherit;
                    $item->inherit->particle = $item->id;

                    if (isset($index['inherit'][$item->inherit->outline][$item->id])) {
                        $item->id = $index['inherit'][$item->inherit->outline][$item->id];
                    } elseif ($type !== 'position' || $subtype !== 'position') {
                        $item->id = $this->id($type, $subtype);
                    }
                }

                if (isset($item->id)) {
                    $this->references[$item->id] = $item;
                    $this->types[$type][$subtype][$item->id] = $item;

                    if (!empty($item->inherit->outline)) {
                        $this->inherit[$item->id] = $item;
                    }
                } else {
                    $this->types[$type][$subtype][] = $item;
                }

                if (isset($item->children) && is_array($item->children)) {
                    $this->initReferences($item->children, $type === 'block' ? $item : null, $inherit, $index);
                }
            }
        }
    }

    /**
     * @param string $type
     * @param string $subtype
     * @param string $id
     * @return string
     */
    protected function id($type, $subtype = null, $id = null)
    {
        $result = [];
        if ($type !== 'particle') {
            $result[] = $type;
        }
        if ($subtype && $subtype !== $type) {
            $result[] = $subtype;
        }
        $key = implode('-', $result);

        if (!$id || isset($this->references[$key][$id])) {
            while ($id = rand(1000, 9999)) {
                if (!isset($this->references[$key][$id])) {
                    break;
                }
            }
        }

        return $key . '-'. $id;
    }

    /**
     * Prepare block width sizes.
     *
     * @return $this
     */
    public function prepareWidths()
    {
        $this->init();

        $this->calcWidths($this->items);

        return $this;
    }

    /**
     * Recalculate block widths.
     *
     * @param array $items
     * @internal
     */
    protected function calcWidths(array &$items)
    {
        foreach ($items as $i => $item) {
            if (empty($item->children)) {
                continue;
            }

            $this->calcWidths($item->children);

            $dynamicSize = 0;
            $fixedSize = 0;
            $childrenCount = 0;
            foreach ($item->children as $child) {
                if ($child->type !== 'block') {
                    continue;
                }
                $childrenCount++;
                if (!isset($child->attributes->size)) {
                    $child->attributes->size = 100 / count($item->children);
                }
                if (empty($child->attributes->fixed)) {
                    $dynamicSize += $child->attributes->size;
                } else {
                    $fixedSize += $child->attributes->size;
                }
            }

            if (!$childrenCount) {
                continue;
            }

            $roundSize = round($dynamicSize, 1);
            $equalized = isset($this->equalized[$childrenCount]) ? $this->equalized[$childrenCount] : 0;

            // force-casting string for testing comparison due to weird PHP behavior that returns wrong result
            if ($roundSize != 100 && (string) $roundSize != (string) ($equalized * $childrenCount)) {
                $fraction = 0;
                $multiplier = (100 - $fixedSize) / ($dynamicSize ?: 1);
                foreach ($item->children as $child) {
                    if ($child->type !== 'block') {
                        continue;
                    }
                    if (!empty($child->attributes->fixed)) {
                        continue;
                    }

                    // Calculate size for the next item by taking account the rounding error from the last item.
                    // This will allow us to approximate cumulating error and fix it when rounding error grows
                    // over the rounding treshold.
                    $size = ($child->attributes->size * $multiplier) + $fraction;
                    $newSize = round($size);
                    $fraction = $size - $newSize;
                    $child->attributes->size = $newSize;
                }
            }
        }
    }

    /**
     * @param  string $name
     * @return static
     */
    public static function load($name)
    {
        if (!$name) {
            throw new \BadMethodCallException('Layout needs to have a name');
        }

        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        $layout = null;
        $preset = null;
        $filename = $locator("gantry-config://{$name}/layout.yaml");

        // If layout file doesn't exists, figure out what preset was used.
        if (!$filename) {
            $index = static::loadIndex($name, true);
            $preset = $index['preset']['name'];

            try {
                $layout = static::preset($preset);
            } catch (\Exception $e) {
                // Layout doesn't exist, do nothing.
            }
        } else {
            $layout = LayoutReader::read($filename);
        }

        return new static($name, $layout);
    }

    /**
     * @param  string $name
     * @param  bool   $autoSave
     * @return static
     */
    public static function loadIndex($name, $autoSave = false)
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        // Attempt to load the index file.
        $indexFile = $locator("gantry-config://{$name}/index.yaml");
        if ($indexFile) {
            $file = CompiledYamlFile::instance($indexFile);
            $index = $file->content();
            $file->free();
        }

        // Find out the currently used layout file.
        $layoutFile = $locator("gantry-config://{$name}/layout.yaml");
        if (!$layoutFile) {
            /** @var Outlines $configurations */
            $configurations = $gantry['configurations'];

            $preset = isset($index['preset']['name']) ? $index['preset']['name'] : $configurations->preset($name);
        }

        // Get timestamp for the layout file.
        $timestamp = $layoutFile ? filemtime($layoutFile) : 0;

        // If layout index file doesn't exist or is not up to date, rebuild it.
        if (!isset($index['timestamp']) || $index['timestamp'] != $timestamp || !isset($index['version']) || $index['version'] != static::VERSION) {
            $layout = isset($preset) ? new static($name, static::preset($preset)) : static::instance($name);
            $layout->timestamp = $timestamp;
            $index = $layout->buildIndex();
        }

        if ($autoSave && isset($layout)) {
            if (!$layout->timestamp) {
                $layout->save();
            }
            $layout->saveIndex($index);
        }

        $index += [
            'name' => $name,
            'timestamp' => $timestamp,
            'preset' => [
                'name' => '',
                'image' => 'gantry-admin://images/layouts/default.png'
            ],
            'positions' => [],
            'sections' => [],
            'inherit' => []
        ];

        return $index;
    }

    public function check(array $children = null)
    {
        if ($children === null) {
            $children = $this->items;
        }

        foreach ($children as $item) {
            if (!$item instanceof \stdClass) {
                throw new \RuntimeException('Invalid layout element');
            }
            if (!isset($item->type)) {
                throw new \RuntimeException('Type missing');
            }
            if (!isset($item->subtype)) {
                throw new \RuntimeException('Subtype missing');
            }
            if (!isset($item->attributes)) {
                throw new \RuntimeException('Attributes missing');
            }
            if (!is_object($item->attributes)) {
                throw new \RuntimeException('Attributes not object');
            }
            if (isset($item->children)) {
                if (!is_array($item->children)) {
                    throw new \RuntimeException('Children not array');
                }
                $this->check($item->children);
            }
        }
    }
}
