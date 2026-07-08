<?php

namespace App\Models\Traits;

trait Validator
{

    /**
     * Extra fields not in fillable to be added to nice names (translation).
     *
     * @var array
     */
    protected $addToNiceNames = [];

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     * @param  int|null $id Optional ID for update operations (to exclude from unique validation)
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data, $id = null)
    {
        // Set the ID on the model if provided (for update operations)
        // This allows unique validation rules to exclude the current record
        if ($id !== null) {
            $this->id = $id;
        }

        $this->fill($data);
        $messages = method_exists($this, 'messages') ? $this->messages() : [];
        $validator = \Validator::make($data, $this->rules(), $messages);
        $validator->setAttributeNames($this->niceNames());

        return $validator;
    }

    /**
     * Translate attributes names.
     *
     * @return array
     */
    protected function niceNames()
    {
        $niceNames = [];
        if (isset($this->fillable)) {
            foreach ($this->fillable as $attribute) {
                $niceNames[$attribute] = $this->niceName($attribute);
            }
        }
        if (isset($this->addToNiceNames)) {
            foreach ($this->addToNiceNames as $attribute) {
                $niceNames[$attribute] = $this->niceName($attribute);
            }
        }

        return $niceNames;
    }

    /**
     * Translate attribute name
     *
     * @param string $attribute
     * @return string the name translated using the associated translation file
     */
    protected function niceName($attribute)
    {
        $translationFile = 'attribute';

        return \Lang::get($translationFile . '.' . $attribute);
    }

    /**
     * Get model name to be used in files (translation ...).
     *
     * @return string
     */
    public function getName()
    {
        $classArr = explode('\\', get_class($this));
        $className = end($classArr);

        // Convert CamelCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
    }
}
