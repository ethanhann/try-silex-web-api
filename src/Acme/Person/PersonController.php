<?php
/**
 * @author  Ethan Hann <ethanhann@gmail.com>
 * @license For the full copyright and license information, please view the LICENSE
 *          file that was distributed with this source code.
 */

namespace Acme\Person;

use Acme\IService;
use Silex\Application;

//use Symfony\Component\HttpFoundation\Request;

/*
 * IService interface indicates that that the class is a web api controller.
 */

class PersonController implements IService
{
    /**
     * @param PersonRequest $request
     * @return PersonResponse
     */
    public function get(PersonRequest $request)
    {
        // use $request to do something
        return (new PersonResponse())->setName($request->getName());
    }
}
