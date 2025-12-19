<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Jenssegers\Model\Model;

/**
 * The Nodal model is a tableless object that can be used without
 * any previous knowledge of attributes.
 */
class Nodal extends Model
{
    use HasFactory;

    /**
     * Automatically assign an ident on instantiation
     *
     * @param	array	$attributes
     * @return 	void
     */
    public function __construct(array $attributes = array())
    {
		parent::__construct($attributes);
    }
}
