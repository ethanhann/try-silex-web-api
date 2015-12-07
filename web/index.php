<?php
/**
 * @author  Ethan Hann <ethanhann@gmail.com>
 * @license For the full copyright and license information, please view the LICENSE
 *          file that was distributed with this source code.
 */

use Ehann\WebServiceControllerInterface;
use Doctrine\Common\Annotations\AnnotationRegistry;
use KPhoen\Provider\NegotiationServiceProvider;
use Macedigital\Silex\Provider\SerializerProvider;
use Symfony\Component\HttpFoundation\Response;
use Zend\Code\Reflection\ClassReflection;

// Load composer dependencies.
$loader = require_once __DIR__ . '/../vendor/autoload.php';

// Load annotations.
AnnotationRegistry::registerLoader('class_exists');

// Create and configure app.
$app = new Silex\Application();
$app->register(new SerializerProvider);
$app->register(new NegotiationServiceProvider(array(
    'json' => array('application/json'),
)));

function get_web_service_classes($namespacePrefix, $sourcePath)
{
    $webServiceInterface = 'Ehann\WebServiceControllerInterface';
    $path = $sourcePath;
    $recursiveIteratorIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
    $regexIterator = new RegexIterator($recursiveIteratorIterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
    $fullyQualifiedClasses = [];
    foreach ($regexIterator as $absolutePath => $object) {
        $namespacePathArray = explode($sourcePath, $absolutePath);
        $namespacePath = array_pop($namespacePathArray);
        $fullyQualifiedClass = sprintf('%s%s\\%s',
            trim($namespacePrefix, '\\'),
            pathinfo($namespacePath, PATHINFO_DIRNAME),
            pathinfo($namespacePath, PATHINFO_FILENAME)
        );
        $fullyQualifiedClasses[] = str_replace('/', '\\', trim($fullyQualifiedClass, '/'));
    }

    return array_filter($fullyQualifiedClasses, function ($class) use ($webServiceInterface) {
        $implementedInterfaces = class_implements($class);
        return $implementedInterfaces !== false ?
            in_array(WebServiceControllerInterface::class, $implementedInterfaces) :
            false;
    });
}

$formatNegotiator = $app['format.negotiator'];
$serializer = $app['serializer'];
$priorities = ['json', 'xml'];
$namespacePrefix = 'Acme\\';
$sourcePath = array_pop($loader->getPrefixesPsr4()[$namespacePrefix]);
$routes = [];

/*
 * Auto-register routes for the Acme namespace.
 */
foreach (get_web_service_classes($namespacePrefix, $sourcePath) as $webServiceClass) {
    // Need to get methods and DTOs from class
    $classReflection = new ClassReflection($webServiceClass);
    $httpMethodReflections = array_filter($classReflection->getMethods(), function ($methodReflection) {
        return in_array($methodReflection->name, ['get', 'getList', 'post', 'put', 'delete']);
    });
    // @todo add a check to make sure DTOs are unique. This might happen implicitly when registering routes.
    // Call for each http method/DTO in web service
    /** @var ReflectionMethod $httpMethodReflection */
    foreach ($httpMethodReflections as $httpMethodReflection) {
        // This assumes that the first argument of the HTTP method is a DTO.
        $httpMethodReflectionPrototype = $httpMethodReflection->getPrototype();
        $requestDtoClass = array_shift($httpMethodReflectionPrototype['arguments'])['type'];
        $requestDtoProperties = (new ClassReflection($requestDtoClass))->getProperties();
        $returnDtoClass = $httpMethodReflection->getReturnType();
        $returnDtoProperties = (new ClassReflection($returnDtoClass))->getProperties();
        $requestMethod = $httpMethodReflectionPrototype['name'];
        $route = '/' . str_replace('\\', '/', pathinfo($requestDtoClass, PATHINFO_FILENAME));
        $routes[] =  new class(
            $route,
            $requestDtoClass,
            $requestDtoProperties,
            $returnDtoClass,
            $returnDtoProperties
        ) {
            public $path;
            public $requestDto;
            public $requestDtoParameters;
            public $returnDto;
            public $returnDtoProperties;

            public function __construct(string $path,
                                        string $requestDto,
                                        array $requestDtoParameters,
                                        string $returnDto,
                                        array $returnDtoProperties)
            {
                $this->path = $path;
                $this->requestDto = $requestDto;
                $this->requestDtoParameters = $requestDtoParameters;
                $this->returnDto = $returnDto;
                $this->returnDtoProperties = $returnDtoProperties;
            }
        };
        $app->get($route, function () use ($app, $formatNegotiator, $serializer, $priorities, $webServiceClass, $requestDtoClass, $requestMethod) {
            $httpRequest = $app['request'];
            // Convert request parameters to the request DTO.
            $params = $serializer->serialize($httpRequest->query->all(), 'json');
            $requestDto = $serializer->deserialize($params, $requestDtoClass, 'json');
            // Get the response DTO by calling the HTTP method of the web service class, with the request DTO.
            $responseDto = (new $webServiceClass)->$requestMethod($requestDto);
            // Content negotiation
            $format = $formatNegotiator->getBestFormat(implode(',', $httpRequest->getAcceptableContentTypes()), $priorities);
            return new Response($serializer->serialize($responseDto, $format), 200, array(
                'Content-Type' => $app['request']->getMimeType($format)
            ));
        });
    }
};

/**
 * Register custom route page
 */
$app->get('_routes', function () use ($app, $formatNegotiator, $serializer, $priorities, $routes) {
    $httpRequest = $app['request'];
    $format = $formatNegotiator->getBestFormat(implode(',', $httpRequest->getAcceptableContentTypes()), $priorities);
    $serializer = $app['serializer'];
    $serializedData = $serializer->serialize($routes, $format);
    $responseCode = Response::HTTP_OK;
    if ($serializedData === false) {
        $serializedData = '';
        $responseCode = Response::HTTP_INTERNAL_SERVER_ERROR;
    }
    return new Response($serializedData, $responseCode, array(
        'Content-Type' => $app['request']->getMimeType($format)
    ));
});

$app['debug'] = true;
$app->run();
