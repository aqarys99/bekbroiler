<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Review;

final class ReviewsController extends SectionController
{
    protected const KEY = 'reviews';
    protected const MODEL = Review::class;
}
