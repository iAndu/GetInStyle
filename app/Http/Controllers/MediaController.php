<?php

namespace App\Http\Controllers;

use App\Media;
use App\Style;
use App\Tag;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Http\Requests\StoreMediaRequest;
use Auth;
use Storage;
use App\Like;

class MediaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $media = Media::with(['user'])->withCount('likes')->withCount('comments')->withCount(['likes as liked' => function ($query) {
            $query->where('user_id', Auth::check() ? Auth::id() : 0);
        }]);

        $media = $media->get();

        if (request()->wantsJson()) {
            return response()->json($media);
        } else {
            $userId = Auth::id();
            return view('media.index', compact('media', 'userId'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $styles = Style::all();
        $tags = Tag::all();

        return view('media.create', compact('styles', 'tags'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreMediaRequest $request)
    {
        $imagePath = 'storage/' . $request->file('userPhoto')->store('media_upload', 'public');
        //return response()->json(asset($imagePath)); //uncomment this when you want to test but styling doesn't work
        $style = Style::find($request->style_id);
        $stylizedImagePath = "storage/media_stylized/" . (Auth::check() ? '' : 'temp_') . str_random() . '.jpg';
        
        $process = new Process('python3 ' . base_path() . '/style_transfer.py --model ' . base_path($style->model_path) . " --image " . public_path("$imagePath") . " --output " . public_path("$stylizedImagePath"));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
            return view('errors.media');
        }

        if (Auth::check()) {
            $media = Media::create([
                'user_id' => Auth::id(),
                'style_id' => $style->id,
                'path' => $imagePath,
                'stylized_path' => $stylizedImagePath,
            ]);
        } else {
            Storage::disk('public')->delete(substr($imagePath, strpos($imagePath, 'media_upload')));
        }

        return response()->json(asset($stylizedImagePath));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Media  $media
     * @return \Illuminate\Http\Response
     */
    public function show(Media $media)
    {
        $media->load(['comments' => function ($query) {
            $query->withCount('likes');
            $query->withCount(['likes as liked' => function ($q) {
                $q->where('user_id', Auth::id());
            }]);
        }, 'comments.replies' => function ($query) {
            $query->withCount('likes');
            $query->withCount(['likes as liked' => function ($q) {
                $q->where('user_id', Auth::id());
            }]);
        }]);
        $media->likes_count = $media->likes()->count();
        $media->liked = $media->likes()->where('user_id', Auth::check() ? Auth::id() : 0)->count();

        $userId = Auth::id();

        if (request()->wantsJson()) {
            return response()->json(['media' => $media]);
        } else {
            return view('media.show', compact('media', 'userId'));        
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Media  $media
     * @return \Illuminate\Http\Response
     */
    public function edit(Media $media)
    {
        return view('media.edit', compact('media'));        
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Media  $media
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Media $media)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Media  $media
     * @return \Illuminate\Http\Response
     */
    public function destroy(Media $media)
    {
        //
    }

    public function photosByUserId($user_id)
    {
        $photos = Auth::user()->media()->get();
        //$media = Media::with['user_id' -> $user_id];
        return view('media.photosByUserId', $photos);
        //return view('media.photosByUserId', $media);
    }

    public function toggleLike(Media $media)
    {
        $userLike = $media->likes()->where('user_id', Auth::id())->first();

        if ($userLike) {
            // Unlike
            $userLike->delete();
        } else {
            // Like
            $like = new Like(['created_at' => now(), 'updated_at' => now(), 'user_id' => Auth::id()]);
            $media->likes()->save($like);
        }

        return response()->json([
            'status' => 'success',
            'message' => '',
        ]);
    }
}
