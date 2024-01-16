<?php

declare(strict_types=1);

namespace Flying\Wordpress\Queue;

class AssetQueue extends \SplPriorityQueue
{
    private int $insertOrder = 0;

    public function insert($value, $priority): bool
    {
        return parent::insert($value, [get_class($this), $priority, $this->insertOrder++]);
    }

    public function compare($priority1, $priority2): int
    {
        if ($this->isValidPriority($priority1) && $this->isValidPriority($priority2)) {
            $result = $priority1[1] <=> $priority2[1];
            if ($result !== 0) {
                return $result;
            }
            return $priority1[2] <=> $priority2[2];
        }
        return $priority1 <=> $priority2;
    }

    private function isValidPriority($priority): bool
    {
        return is_array($priority) && count($priority) === 3 && $priority[0] === get_class($this);
    }
}

