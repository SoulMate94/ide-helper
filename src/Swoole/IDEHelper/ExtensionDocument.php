<?php

namespace Swoole\IDEHelper;

use Reflection;
use ReflectionClass;
use ReflectionException;
use ReflectionExtension;
use ReflectionMethod;
use ReflectionProperty;

class ExtensionDocument
{
    const EXTENSION_NAME = 'swoole';

    const C_METHOD = 1;
    const C_PROPERTY = 2;
    const C_CONSTANT = 3;
    const SPACE_4 = '    ';
    const SPACE_5 = self::SPACE_4 . ' ';

    /**
     * @var string
     */
    protected $language;

    /**
     * @var string
     */
    protected $dirConfig;

    /**
     * @var string
     */
    protected $dirOutput;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var ReflectionExtension
     */
    protected $rf_ext;

    /**
     * ExtensionDocument constructor.
     *
     * @param string $language
     * @param string $dirOutput
     * @param string $dirConfig
     * @throws ReflectionException
     */
    public function __construct(string $language, string $dirOutput, string $dirConfig)
    {
        if (!extension_loaded(self::EXTENSION_NAME)) {
            throw new \Exception("no " . self::EXTENSION_NAME . " extension.");
        }

        $this->language  = $language;
        $this->dirOutput = $dirOutput;
        $this->dirConfig = $dirConfig;
        $this->rf_ext    = new ReflectionExtension(self::EXTENSION_NAME);
        $this->version   = $this->rf_ext->getVersion();
    }

    public function export(): void
    {
        /**
         * 获取所有define常量
         */
        $consts = $this->rf_ext->getConstants();
        $defines = '';
        foreach ($consts as $className => $ref) {
            if (!is_numeric($ref)) {
                $ref = "'$ref'";
            }
            $defines .= "define('$className', $ref);\n";
        }

        if (!is_dir($this->dirOutput)) {
            mkdir($this->dirOutput);
        }

        file_put_contents(
            $this->dirOutput . '/constants.php',
            "<?php\n" . $defines
        );

        /**
         * 获取所有函数
         */
        $funcs = $this->rf_ext->getFunctions();
        $fdefs = $this->getFunctionsDef($funcs);

        file_put_contents(
            $this->dirOutput . '/functions.php',
            "<?php\n/**\n * @since {$this->version}\n */\n\n{$fdefs}"
        );

        /**
         * 获取所有类
         */
        $classes = $this->rf_ext->getClasses();
        $class_alias = "<?php\n";
        // There are three types of class names in Swoole:
        // 1. short name of a class. Short names start with "co\". These classes can be found under folder output/alias.
        // 2. fully qualified name (class name with namespace prefix), e.g., \Swoole\Timer. These classes can be found
        //    under folder output/namespace.
        // 3. snake_case. e.g., swoole_timer. These classes can be found in file output/classes.php.
        foreach ($classes as $className => $ref) {
            if (strtolower(substr($className, 0, 3)) == 'co\\') {
                $this->exportShortAlias($className);
            } elseif (strchr($className, '\\')) {
                $this->exportNamespaceClass($className, $ref);
            } else {
                $class_alias .= sprintf(
                    "class_alias(%s::class, '%s');\n",
                    self::getNamespaceAlias($className),
                    $className
                );
            }
        }
        file_put_contents(
            $this->dirOutput . '/classes.php',
            $class_alias
        );
    }

    // static function isPHPKeyword($word)
    // {
    //     $keywords = array('exit', 'die', 'echo', 'class', 'interface', 'function', 'public', 'protected', 'private');
    //
    //     return in_array($word, $keywords);
    // }

    /**
     * @param string $comment
     * @return string
     */
    protected static function formatComment(string $comment): string
    {
        $lines = explode("\n", $comment);
        foreach ($lines as &$li) {
            $li = ltrim($li);
            if (isset($li[0]) && $li[0] != '*') {
                $li = self::SPACE_5 . '*' . $li;
            } else {
                $li = self::SPACE_5 . $li;
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string $className
     */
    protected function exportShortAlias(string $className): void
    {
        if (strtolower(substr($className, 0, 2)) != 'co') {
            return;
        }
        $ns = explode('\\', $className);
        foreach ($ns as &$n) {
            $n = ucfirst($n);
        }
        $path = $this->dirOutput . '/alias/' . implode('/', array_slice($ns, 1)) . '.php';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $extends = ucwords(str_replace('co\\', 'Swoole\\Coroutine\\', $className), '\\');
        if (!class_exists($extends)) {
            $extends = ucwords(str_replace('co\\', 'Swoole\\', $className), '\\');
        }
        $content = sprintf(
            "<?php\nnamespace %s \n{\n" . self::SPACE_5 . "class %s extends \%s {}\n}\n",
            implode('\\', array_slice($ns, 0, count($ns) - 1)),
            end($ns),
            $extends
        );
        file_put_contents($path, $content);
    }

    /**
     * @param string $className
     * @return string
     */
    protected static function getNamespaceAlias(string $className): string
    {
        if (strtolower($className) == 'co') {
            return "Swoole\\Coroutine";
        } elseif (strtolower($className) == 'chan') {
            return "Swoole\\Coroutine\\Channel";
        } else {
            return str_replace('_', '\\', ucwords($className, '_'));
        }
    }

    /**
     * @param string $class
     * @param string $name
     * @param string $type
     * @return array
     */
    protected function getConfig(string $class, string $name, string $type): array
    {
        switch ($type) {
            case self::C_CONSTANT:
                $dir = 'constant';
                break;
            case self::C_METHOD:
                $dir = 'method';
                break;
            case self::C_PROPERTY:
                $dir = 'property';
                break;
            default:
                return false;
        }
        $file = $this->dirConfig . '/' . $this->language . '/' . strtolower($class) . '/' . $dir . '/' . $name . '.php';
        if (is_file($file)) {
            return include $file;
        } else {
            return array();
        }
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string|null
     */
    protected static function getDefaultValue(\ReflectionParameter $parameter): ?string
    {
        try {
            $default_value = $parameter->getDefaultValue();
            if ($default_value === []) {
                $default_value = '[]';
            } elseif ($default_value === null) {
                $default_value = 'null';
            } elseif (is_bool($default_value)) {
                $default_value = $default_value ? 'true' : 'false';
            } else {
                $default_value = var_export($default_value, true);
            }
        } catch (\Throwable $e) {
            if ($parameter->isOptional()) {
                $default_value = 'null';
            } else {
                $default_value = null;
            }
        }
        return $default_value;
    }

    /**
     * @param ReflectionMethod[] $functions
     * @return string
     */
    protected function getFunctionsDef(array $functions): string
    {
        $all = '';
        foreach ($functions as $function_name => $function) {
            $comment = '';
            $vp = array();
            $params = $function->getParameters();
            if ($params) {
                $comment = "/**\n";
                foreach ($params as $param) {
                    $default_value = self::getDefaultValue($param);
                    $comment .= " * @param \${$param->name}[" .
                        ($param->isOptional() ? 'optional' : 'required') .
                        "]\n";
                    $vp[] = ($param->isPassedByReference() ? '&' : '') .
                        "\${$param->name}" .
                        ($default_value ? " = {$default_value}" : '');
                }
                $comment .= " * @return mixed\n";
                $comment .= " */\n";
            }
            $comment .= sprintf("function %s(%s){}\n\n", $function_name, join(', ', $vp));
            $all .= $comment;
        }

        return $all;
    }

    /**
     * @param ReflectionProperty[] $props
     * @return string
     */
    protected function getPropertyDef(array $props): string
    {
        $prop_str = "";
        foreach ($props as $k => $v) {
            $modifiers = implode(' ', Reflection::getModifierNames($v->getModifiers()));
            $prop_str .= self::SPACE_4 . "{$modifiers} $" . $v->name . ";\n";
        }

        return $prop_str;
    }

    /**
     * @param string[] $consts
     * @return string
     */
    protected function getConstantsDef(array $consts): string
    {
        $all = "";
        foreach ($consts as $k => $v) {
            $all .= self::SPACE_4 . "const {$k} = ";
            if (is_int($v)) {
                $all .= "{$v};\n";
            } else {
                $all .= "'{$v}';\n";
            }
        }
        return $all;
    }

    /**
     * @param $classname
     * @param ReflectionMethod[] $methods
     * @return string
     */
    protected function getMethodsDef(string $classname, array $methods): string
    {
        $all = '';
        foreach ($methods as $k => $v) {
            if ($v->isFinal()) {
                continue;
            }

            $method_name = $v->name;

            $vp = array();
            $comment = self::SPACE_4 . "/**\n";

            $config = $this->getConfig($classname, $method_name, self::C_METHOD);
            if (!empty($config['comment'])) {
                $comment .= self::formatComment($config['comment']);
            }

            $params = $v->getParameters();
            if ($params) {
                foreach ($params as $param) {
                    $default_value = self::getDefaultValue($param);
                    $comment .= self::SPACE_5 .
                        "* @param \${$param->name}[" .
                        ($param->isOptional() ? 'optional' : 'required') .
                        "]\n";
                    $vp[] = ($param->isPassedByReference() ? '&' : '') .
                        "\${$param->name}" .
                        ($default_value ? " = {$default_value}" : '');
                }
            }
            if (!isset($config['return'])) {
                $comment .= self::SPACE_5 . "* @return mixed\n";
            } elseif (!empty($config['return'])) {
                $comment .= self::SPACE_5 . "* @return {$config['return']}\n";
            }
            $comment .= self::SPACE_5 . "*/\n";
            $modifiers = implode(
                ' ',
                Reflection::getModifierNames($v->getModifiers())
            );
            $comment .= sprintf(self::SPACE_4 . "%s function %s(%s){}\n\n", $modifiers, $method_name, join(', ', $vp));
            $all .= $comment;
        }

        return $all;
    }

    /**
     * @param string $classname
     * @param ReflectionClass $ref
     */
    protected function exportNamespaceClass(string $classname, ReflectionClass $ref): void
    {
        $ns = explode('\\', $classname);
        if (strtolower($ns[0]) != self::EXTENSION_NAME) {
            return;
        }

        array_walk($ns, function (&$v, $k) use (&$ns) {
            $v = ucfirst($v);
        });


        $path = $this->dirOutput . '/namespace/' . implode('/', array_slice($ns, 1));

        $namespace = implode('\\', array_slice($ns, 0, -1));
        $dir = dirname($path);
        $name = basename($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $content = "<?php\nnamespace {$namespace};\n\n" . $this->getClassDef($name, $ref);
        file_put_contents($path . '.php', $content);
    }

    /**
     * @param string $classname
     * @param ReflectionClass $ref
     * @return string
     */
    protected function getClassDef(string $classname, ReflectionClass $ref): string
    {
        //获取属性定义
        $props = $this->getPropertyDef($ref->getProperties());

        if ($ref->getParentClass()) {
            $classname .= ' extends \\' . $ref->getParentClass()->name;
        }
        $modifier = 'class';
        if ($ref->isInterface()) {
            $modifier = 'interface';
        }
        //获取常量定义
        $consts = $this->getConstantsDef($ref->getConstants());
        //获取方法定义
        $mdefs = $this->getMethodsDef($classname, $ref->getMethods());
        $class_def = sprintf(
            "%s %s\n{\n%s\n%s\n%s\n}\n",
            $modifier,
            $classname,
            $consts,
            $props,
            $mdefs
        );
        return $class_def;
    }
}