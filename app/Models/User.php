<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

use Carbon\Carbon;

/**
 *
 * @category   Model
 * @package    GenCC
 * @author     P. Weller <pweller1@geisinger.edu>
 * @copyright  2024 Geisinger
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    Release: @package_version@
 * @link       http://pear.php.net/package/PackageName
 * @see        NetOther, Net_Sample::Net_Sample()
 * @since      Class available since Release 1.0.0
 *
 * The users table holds all information pertaining to the individual login accounts
 * for the application.
 *
 * */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ident', 'type',
        'name', 'first_name', 'last_name', 'title', 'phone',
        'email', 'profile', 'preferences', 'submitter_id', 'team_id',
        'clingen_id', 'api_token', 'api_token_renewed_at',
        'password', 'role', 'status', 'email_verified_at',
        'must_change_password'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'profile' => 'object',
        'preferences' => 'object',
        'api_token_renewed_at' => 'datetime',
        'must_change_password' => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        //
    ];


    /**
     * Enumerted constants for status
     */
    public const STATUS_INITIALIZING = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_ACTIVE_NO_API = 2;
    public const STATUS_LOCKED = 20;
    public const STATUS_REMOVED = 9;

    /*
     * Status strings for display methods
     *
     * @var array
     * */
     protected $status_strings = [
	 		0 => 'Initializing',
	 		1 => 'Active',
	 		2 => 'Locked',
	 		9 => 'Deleted'
     ];



    /**
     * The boot methods allow us to automatically set values that are either not
     * provided on registration, or require automated assignment by the application.
     */
    protected static function boot()
    {
        parent::boot();

        /**
         * Registration only provides a full name, which we want to split out
         * first and last name from the full name for convenience.  We can do it automatically
         * on record creation.
         */
        static::creating(function ($model) {

            if (empty($model->first_name) && empty($model->last_name))
            {
                $names = explode(' ', $model->name, 2);
                $model->first_name = $names[0];
                $model->last_name = $names[1] ?? '';
            }
        });

        /**
         * After user creation, set up auto-generated fields:
         * - clingen_id: unique GenCC user identifier based on record ID
         * - api_token: initial API token for authentication
         * - preferences: default user preferences structure
         */
        static::created(function ($model) {
            // Find the next sequential CUID by checking max existing value
            $maxNum = self::where('clingen_id', 'like', 'CUID:3%')
                ->selectRaw('MAX(CAST(SUBSTRING(clingen_id, 7) AS UNSIGNED)) as max_num')
                ->value('max_num');
            $nextNum = ($maxNum ?? 0) + 1;
            $model->clingen_id = 'CUID:3' . sprintf('%05d', $nextNum);
            $model->add_api_token();
            $model->preferences = $model->initialize_preferences();
            $model->save();
        });
    }


     /**
     * Automatically assign an ident on instantiation
     *
     * @param	array	$attributes
     * @return 	void
     */
    public function __construct(array $attributes = array())
    {
        $this->attributes['ident'] = Str::uuid()->toString();
        $this->attributes['profile'] = '[]';
        $this->attributes['preferences'] = '[]';

        parent::__construct($attributes);
    }


    /**
     * Get the primary submitter associated with this user (legacy, uses submitter_id)
     */
    public function submitter()
    {
       return $this->belongsTo('App\Models\Submitter');
    }

    /**
     * Get all submitters associated with this user (via pivot)
     */
    public function submitters()
    {
       return $this->belongsToMany('App\Models\Submitter', 'submitter_user')
                   ->withPivot('is_contact')
                   ->withTimestamps();
    }


    /**
     * Get the submissions associated with this user
     *
     */
    public function submissions()
    {
       return $this->hasMany('App\Models\Submission');
    }


    /**
     * Get the jobs associated with this user
     */
    public function jobs()
    {
       return $this->hasMany('App\Models\Job');
    }


    /**
     * Get the aliases associated with this user
     */
    public function aliases()
    {
       return $this->hasMany('App\Models\Alias');
    }


    /**
     * Get all the documents associated with this submitter
     */
    public function documents()
    {
       return $this->hasMany('App\Models\Document');
    }


    /**
     * Query scope by ident
     *
     * @param	string	$ident
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeIdent($query, $ident)
    {
		return $query->where('ident', $ident);
    }


    /**
     * Query scope by clingenid
     *
     * @param	string	$cuid
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeCuid($query, $cuid)
    {
		return $query->where('clingen_id', $cuid);
    }


    /**
     * Query scope by api_token
     *
     * @param	string	$token
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeApi($query, $token)
    {
		return $query->where('api_token', $token);
    }


    /**
     * Query scope account status
     *
     * @param	string	$ident
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeActive($query)
    {
		return $query->where('status', self::STATUS_ACTIVE);
    }


    /**
     * Add or modiy the api_token for this user amd reset the
     * renew date.
     *
     */
    public function add_api_token()
    {
        $this->api_token = Str::random(60);
        $this->api_token_renewed_at = Carbon::now();
    }


    /**
     * Set name, first name, and last name from the given argument
     *
     */
    public function add_name($name = null)
    {
        if (!empty($name))
        {
            $this->name = $name;
            $parts = explode(" ", $name);
            $this->first_name = $parts[0];
            $this->last_name = $parts[1] ?? '';
        }
    }


    /**
     * Create and return a new encrypted password off the clear text one
     *
     * $params string $pw
     * @return string
     *
     */
    public function make_password($pw = null)
    {
        if (empty($pw))
            return null;

        return bcrypt($pw);
    }


    /**
     * Initialize the preferences structure.  It might be better to do this in the
     * constructor for a new record, but while fields are being defined, it is easier
     * to keep external
     */
    public function initialize_preferences()
    {
        $preferences = ['dash_sub_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                        'dash_class_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0],
                        'job_favorites' => [],
                        'sub_favorites' => []
                    ];

        return $preferences;
    }


    /**
     * Check if user is GenCC Administrator
     *
     * @return bool
     */
    public function isGenccAdmin()
    {
        // GenCC Administrator is identified by membership in the "admin" Team
        return $this->teams()->where('teams.name', 'admin')->where('personal_team', false)->exists();
    }


    /**
     * Factory method to create or update a user with standard settings.
     *
     * @param array $data User data with keys:
     *   - name (required): Full name
     *   - email (required): Email address
     *   - password (required): Plaintext password (will be hashed)
     *   - submitter_id (required): ID of the submitter to associate with
     *   - phone (optional): Phone number
     *   - status (optional): User status (default: STATUS_ACTIVE)
     *   - upsert (optional): If true, update existing user if found by email
     * @return User The created or updated user
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function createUser(array $data): User
    {
        // Validate required fields
        $required = ['name', 'email', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $upsert = $data['upsert'] ?? false;

        // Check for existing user
        $existingUser = self::where('email', $data['email'])->first();
        if ($existingUser && !$upsert) {
            throw new \InvalidArgumentException("User with email '{$data['email']}' already exists");
        }

        // Prepare user attributes
        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'submitter_id' => $data['submitter_id'] ?? null,
            'status' => $data['status'] ?? self::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'title' => $data['title'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];

        if ($existingUser) {
            // Update existing user (upsert mode)
            $existingUser->update($attributes);
            // Ensure API token exists
            if (empty($existingUser->api_token)) {
                $existingUser->add_api_token();
                $existingUser->save();
            }
            return $existingUser->fresh();
        }

        // Create new user
        $user = self::create($attributes);

        // Create personal team for the user if they don't have one
        if (!$user->ownedTeams()->where('personal_team', true)->exists()) {
            $firstName = explode(' ', $user->name, 2)[0];
            $team = new \App\Models\Team(['name' => "{$firstName}'s Team"]);
            $team->user_id = $user->id;
            $team->personal_team = true;
            $team->save();
        }

        return $user;
    }
}

