<?php

namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\State;
use App\Models\City;
use App\Events\UserRegistered;
use Illuminate\Support\Facades\Hash;

use App\Exports\UsersExport;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\StoreUserRequest;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Auth;


class UserController extends Controller {
    public function index(Request $request)
    {
        $query = User::with(['city', 'state', 'roles'])->where('id', '!=', Auth::id());
    
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                  ->orWhere('last_name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }
    
        if ($request->ajax()) {
            return response()->json($query->paginate(5));
        }
    
        $users = $query->paginate(5);
        return view('users.index', compact('users'));
    }

  
//    public function index(Request $request) {
//     $query = User::with(['city', 'state', 'roles'])->where('id', '!=', Auth::id());

//     if ($request->ajax()) {
//         return response()->json($query->paginate(3));
//     }

//     $users = $query->paginate(3);
//     return view('users.index', compact('users'));
// }
   








    public function create() {
        $states = State::all();
        return view('users.create', compact('states'));
    }


    public function store(StoreUserRequest $request) {
        Log::info('📝 Form Submitted Data: ', $request->all());
    
        try {
            $validated = $request->validated();
            
            $uploadedFiles = [];
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $filePath = $file->storeAs('uploads', $file->getClientOriginalName(), 'public');
                    Storage::setVisibility('uploads/' . $file->getClientOriginalName(), 'public');
                    Log::info('📂 File uploaded: ' . $filePath);
                    $uploadedFiles[] = $filePath;
                }
            }
    
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'contact_number' => $validated['contact_number'],
                'postcode' => $validated['postcode'],
                'password' => Hash::make($validated['password']),
                'gender' => $validated['gender'],
                'state_id' => $validated['state_id'],
                'city_id' => $validated['city_id'],
                'roles' => json_encode($validated['roles']),
                'hobbies' => json_encode($validated['hobbies'] ?? []),
                'uploaded_files' => json_encode($uploadedFiles)
            ]);
    
            Log::info('✅ User Created Successfully: ', ['id' => $user->id]);
            event(new UserRegistered($user));
            return redirect()->route('users.index')->with('success', 'User Created Successfully');
        } catch (\Exception $e) {
            Log::error('❌ Error Saving User: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Something went wrong!'])->withInput();
        }
    }
    
    public function edit(User $user) {
        $states = State::all();
        $cities = City::where('state_id', $user->state_id)->get();
        return view('users.edit', compact('user', 'states', 'cities'));
    }

    public function update(UpdateUserRequest $request, User $user) {
        Log::info('📝 Updating User Data: ', $request->all());
    
        try {
            $validated = $request->validated();
    
            $uploadedFiles = json_decode($user->uploaded_files, true) ?? [];
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $filePath = $file->storeAs('uploads', $file->getClientOriginalName(), 'public');
                    Storage::setVisibility('uploads/' . $file->getClientOriginalName(), 'public');
                    Log::info('📂 File uploaded: ' . $filePath);
                    $uploadedFiles[] = $filePath;
                }
            }
    
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'contact_number' => $validated['contact_number'],
                'postcode' => $validated['postcode'],
                'gender' => $validated['gender'],
                'state_id' => $validated['state_id'],
                'city_id' => $validated['city_id'],
                'roles' => json_encode($validated['roles']),
                'uploaded_files' => json_encode($uploadedFiles)
            ]);
    
            Log::info('✅ User Updated Successfully: ', ['id' => $user->id]);
    
            return redirect()->route('users.index')->with('success', 'User Updated Successfully');
        } catch (\Exception $e) {
            Log::error('❌ Error Updating User: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Something went wrong!'])->withInput();
        }
    }
    

    public function destroy(User $user) {
        try {
            // Prevent users from deleting their own accounts
            if ($user->id === Auth::id()) {
                return back()->withErrors(['error' => 'You cannot delete your own account.']);
            }
            
            // Delete associated files
            $uploadedFiles = json_decode($user->uploaded_files, true) ?? [];
            foreach ($uploadedFiles as $file) {
                Storage::disk('public')->delete($file);
                Log::info('🗑️ File deleted: ' . $file);
            }

            $user->delete();
            Log::info('✅ User Deleted Successfully: ', ['id' => $user->id]);

            return redirect()->route('users.index')->with('success', 'User Deleted Successfully');
        } catch (\Exception $e) {
            Log::error('❌ Error Deleting User: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Something went wrong!']);
        }
    }


    public function getCities(Request $request) {
        $cities = City::where('state_id', $request->state_id)->get();
        return response()->json($cities);
    }
    

    public function assignUserRole($userId, $roleId)
    {
        $user = User::find($userId);
        $role = Role::find($roleId);

        if (!$user || !$role) {
            return back()->with('error', 'User or role not found.');
        }

        $user->assignRole($role);
        return back()->with('success', 'Role assigned successfully!');
    }

    public function getUsers()
    {
        // Exclude the logged-in user and paginate with relationships
        return response()->json(
            User::where('id', '!=', Auth::id())
                ->with(['city', 'state', 'roles'])
                ->paginate(3)
        );
    }

    // CSV Export
public function exportCSV() {
    return Excel::download(new UsersExport, 'users.csv');
}

// Excel Export
public function exportExcel() {
    return Excel::download(new UsersExport, 'users.xlsx');
}

// PDF Export
public function exportPDF() {
    $users = User::with(['city', 'state', 'roles'])->where('id', '!=', Auth::id())->get();
    $pdf = Pdf::loadView('exports.users_pdf', compact('users'));
    return $pdf->download('users.pdf');
}
}