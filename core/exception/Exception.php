<?php
    /* 
        the error raport use Atom
    */

    namespace Atom\core\Exception;

    use Atom\core\exception\ForbiddenException;
    use Atom\core\exception\NotFoundException;

    class Exception 
    {

        protected Exception $exception;

        public function __construct() {
            $this->exception = new \Exception();
        }
    }
    

    